<?php

namespace Tests\Feature;

use App\Domain\Games\GameService;
use App\Domain\Games\GameSession;
use App\Domain\Games\GameStatus;
use App\Domain\Games\GameType;
use App\Domain\Games\GuessInterestService;
use App\Domain\Profiles\Interest;
use App\Domain\Telegram\InteractionState;
use App\Domain\Telegram\Jobs\DeliverSocialNotification;
use App\Domain\Users\BlockService;
use App\Domain\Users\User;
use App\Domain\Users\UserStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithGuessGames;
use Tests\TestCase;

class GuessInterestTest extends TestCase
{
    use InteractsWithGuessGames, RefreshDatabase;

    private GuessInterestService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootGameTests();
        $this->service = app(GuessInterestService::class);
    }

    private function type(): GameType
    {
        return GameType::GuessInterest;
    }

    private function prompt(GameSession $s): array
    {
        $s = $s->fresh();

        return json_decode(DB::table('game_rounds')->where('game_session_id', $s->id)->where('round_number', $s->current_round)->value('prompt'), true);
    }

    public function test_real_normalized_opponent_interest_and_three_valid_distractors(): void
    {
        [$a, $b, $s] = $this->game();
        $p = $this->prompt($s);
        $real = $b->profile->interests()->pluck('name')->all();
        $this->assertContains($p['answer'], $real);
        $this->assertCount(4, array_unique($p['options']));
        $this->assertCount(1, array_intersect($real, $p['options']));
        $this->assertSame([], array_diff($p['options'], Interest::pluck('name')->all()));
    }

    public function test_options_are_server_shuffled_and_stable_for_repeated_views(): void
    {
        config(['guess_interest.questions_per_player' => 12]);
        [$a, $b, $s] = $this->game();
        $positions = [];
        for ($i = 0; $i < 24; $i++) {
            $s = $s->fresh();
            $p = $this->prompt($s);
            $q = $this->service->question($s);
            $this->assertSame($q, $this->service->question($s));
            $this->assertArrayNotHasKey('answer', $q);
            $positions[] = array_search($p['answer'], $q['options'], true);
            $this->service->answer(User::findOrFail($s->state['gi_turn_user_id']), $s, $p['answer']);
        }
        $this->assertGreaterThan(1, count(array_unique($positions)));
    }

    public function test_correct_and_incorrect_answers_are_resolved_on_server(): void
    {
        [$a, $b, $s] = $this->game();
        $p = $this->prompt($s);
        $this->assertTrue($this->service->answer($a, $s, array_search($p['answer'], $p['options'], true))['correct']);
        $p = $this->prompt($s);
        $wrong = array_values(array_diff($p['options'], [$p['answer']]))[0];
        $this->assertFalse($this->service->answer($b, $s->fresh(), $wrong)['correct']);
        $this->assertSame(1, $s->fresh()->state['gi_correct_guesses'][$a->id]);
        $this->assertSame(0, $s->fresh()->state['gi_correct_guesses'][$b->id]);
    }

    public function test_wrong_turn_outsiders_and_tampered_options_are_rejected(): void
    {
        [$a, $b, $s] = $this->game();
        $c = $this->user('C');
        $this->rejects(fn () => $this->service->answer($b, $s, 0));
        $this->rejects(fn () => $this->service->answer($c, $s, 0));
        $this->rejects(fn () => $this->service->view($c, $s));
        foreach ([-1, 4, 999, 'winner=1;xp=999;correct=true'] as $option) {
            $this->rejects(fn () => $this->service->answer($a, $s, $option));
        }
        $this->rejects(fn () => $this->service->answer($a, $s, 0, 999));
        $this->assertDatabaseCount('game_answers', 0);
        $this->assertDatabaseCount('game_player_stats', 0);
    }

    public function test_duplicate_and_old_callbacks_do_not_advance_twice(): void
    {
        [$a, $b, $s] = $this->game();
        $this->service->answer($a, $s, 0, 1);
        $this->service->answer($b, $s->fresh(), 0, 2);
        $before = $s->fresh()->state;
        $this->assertTrue($this->service->answer($a, $s->fresh(), 3, 1)['duplicate']);
        $this->assertSame($before, $s->fresh()->state);
        $this->assertDatabaseCount('game_answers', 2);
        $this->assertSame(3, $s->fresh()->current_round);
    }

    public function test_symmetric_turns_xp_once_no_rating_events_or_coins(): void
    {
        [$a, $b, $s] = $this->game();
        $turns = [$a->id => 0, $b->id => 0];
        for ($i = 0; $i < 10; $i++) {
            $s = $s->fresh();
            $u = User::findOrFail($s->state['gi_turn_user_id']);
            $turns[$u->id]++;
            $this->service->answer($u, $s, $this->prompt($s)['answer']);
        }
        $this->assertSame([$a->id => 5, $b->id => 5], $turns);
        $this->assertTrue($this->service->answer($b, $s->fresh(), 0, 10)['duplicate']);
        app(GameService::class)->settleSocial($s, 99999);
        foreach ([$a, $b] as $u) {
            $stats = app(GameService::class)->stats($u);
            $this->assertSame(25, $stats->xp);
            $this->assertSame(1, $stats->games_played);
            $this->assertSame(1000, $stats->competitive_score);
            $this->assertSame(0, $stats->wins + $stats->losses + $stats->current_streak);
            $this->assertSame(25, $this->service->view($u, $s)['xp']);
        }
        $this->assertDatabaseCount('game_rating_events', 0);
        $this->assertDatabaseCount('coin_transactions', 0);
        $this->assertSame(GameStatus::Completed, $s->fresh()->status);
    }

    public function test_no_interest_user_cancels_gracefully_without_rewards(): void
    {
        [$a, $b, $s] = $this->game(false);
        $b->profile->interests()->detach();
        $this->send($b, 'd:0:game_accept_'.$s->id);
        $this->assertSame(GameStatus::Cancelled, $s->fresh()->status);
        $this->assertStringContainsString('cannot start', $this->delivered($b));
        $this->assertDatabaseCount('game_rounds', 0);
        $this->assertDatabaseCount('game_player_stats', 0);
        $this->assertDatabaseCount('coin_transactions', 0);
        $this->assertSame(0, InteractionState::where('mode', 'game_guess_interest')->count());
    }

    public function test_insufficient_distractors_cannot_create_ambiguous_question(): void
    {
        [$a, $b, $s] = $this->game(false);
        $b->profile->interests()->sync(Interest::pluck('id'));
        app(GameService::class)->accept($b, $s);
        $this->assertSame(GameStatus::Cancelled, $s->fresh()->status);
        $this->assertDatabaseCount('game_rounds', 0);
    }

    public function test_inviter_cannot_accept_and_acceptance_is_idempotent(): void
    {
        [$a, $b, $s] = $this->game(false);
        $this->rejects(fn () => app(GameService::class)->accept($a, $s));
        app(GameService::class)->accept($b, $s);
        $before = $s->fresh()->state;
        app(GameService::class)->accept($b, $s);
        $this->service->start($s);
        $this->assertSame($before, $s->fresh()->state);
        $this->assertDatabaseCount('game_rounds', 1);
    }

    public function test_block_prevents_actions_and_queued_delivery(): void
    {
        [$a, $b, $s] = $this->game();
        app(BlockService::class)->block($a, $b);
        $this->rejects(fn () => $this->service->answer($a, $s, 0));
        $this->rejects(fn () => $this->service->view($b, $s));
        foreach (DB::table('social_outbox')->pluck('id') as $id) {
            (new DeliverSocialNotification($id))->handle($this->telegram);
        }
        $this->assertSame([], $this->telegram->sent);
        $this->assertSame(GameStatus::Cancelled, $s->fresh()->status);
        $this->assertDatabaseCount('game_player_stats', 0);
    }

    public function test_expiry_commits_cancellation_and_clears_context(): void
    {
        [$a, $b, $s] = $this->game();
        InteractionState::create(['user_id' => $a->id, 'mode' => 'game_guess_interest', 'game_context' => ['session_id' => $s->id]]);
        $s->update(['expires_at' => now()->subSecond()]);
        $this->rejects(fn () => $this->service->answer($a, $s, 0));
        $this->assertSame(GameStatus::Expired, $s->fresh()->status);
        $this->assertDatabaseHas('interaction_states', ['user_id' => $a->id, 'mode' => 'menu', 'game_context' => null]);
        $this->assertDatabaseCount('game_player_stats', 0);
    }

    public function test_suspended_banned_deleted_and_invalid_profile_cannot_play(): void
    {
        foreach ([UserStatus::Suspended, UserStatus::Banned, UserStatus::Deleted] as $status) {
            [$a, $b, $s] = $this->game();
            $b->update(['status' => $status]);
            $this->rejects(fn () => $this->service->answer($a, $s, 0));
            $this->assertSame(GameStatus::Cancelled, $s->fresh()->status);
        }
        [$a, $b, $s] = $this->game();
        $b->profile->update(['status' => 'draft']);
        $this->rejects(fn () => $this->service->answer($a, $s, 0));
        $this->assertDatabaseCount('game_answers', 0);
        $this->assertDatabaseCount('game_player_stats', 0);
    }

    public function test_generic_entry_points_cannot_bypass_rules(): void
    {
        [$a, $b, $s] = $this->game();
        $this->rejects(fn () => app(GameService::class)->answer($a, $s, 'correct'));
        $this->rejects(fn () => app(GameService::class)->ensureRound($s));
        $this->rejects(fn () => app(GameService::class)->settleSocial($s, 999));
        $this->assertDatabaseCount('game_player_stats', 0);
    }

    public function test_full_telegram_invitation_to_symmetric_final_results_and_privacy(): void
    {
        config(['guess_interest.questions_per_player' => 1]);
        $a = $this->user('A');
        $b = $this->user('B');
        foreach ([$a, $b] as $u) {
            InteractionState::create(['user_id' => $u->id, 'mode' => 'chat']);
        }
        $this->send($a, 'd:0:game_play_guess_interest_'.$b->id);
        $s = GameSession::firstOrFail();
        $this->send($b, 'd:0:game_accept_'.$s->id);
        foreach ([$a, $b] as $i => $u) {
            $this->send($u, 'd:0:game_gi_open_'.$s->id);
            $p = $this->prompt($s);
            $index = array_search($p['answer'], $p['options'], true);
            if ($i === 1) {
                $index = ($index + 1) % 4;
            }
            $round = $i + 1;
            $callback = 'd:0:game_gi_answer_'.$s->id.'_'.$round.'_'.$index;
            $this->send($u, $callback);
            $this->send($u, $callback);
        }
        $this->assertSame(GameStatus::Completed, $s->fresh()->status);
        $this->assertDatabaseCount('game_answers', 2);
        $this->assertSame(17, app(GameService::class)->stats($a)->xp);
        $this->assertSame(15, app(GameService::class)->stats($b)->xp);
        $this->assertStringContainsString('Correct!', $this->delivered($a));
        $this->assertStringContainsString('Incorrect.', $this->delivered($b));
        foreach ([$a, $b] as $u) {
            $this->assertStringContainsString('Which interest', $this->delivered($u));
            $this->assertStringContainsString('Guess Interest complete', $this->delivered($u));
        }
        $this->assertSame(0, InteractionState::where('mode', 'game_guess_interest')->count());
        $this->assertPrivateMessages($a, $b);
        $this->assertDatabaseCount('game_rating_events', 0);
        $this->assertDatabaseCount('coin_transactions', 0);
    }
}
