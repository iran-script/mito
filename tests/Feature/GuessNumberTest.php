<?php

namespace Tests\Feature;

use App\Domain\Games\GameService;
use App\Domain\Games\GameSession;
use App\Domain\Games\GameStatus;
use App\Domain\Games\GameType;
use App\Domain\Games\GuessNumberService;
use App\Domain\Memberships\GoldMembershipService;
use App\Domain\Telegram\InteractionState;
use App\Domain\Telegram\Jobs\DeliverSocialNotification;
use App\Domain\Users\BlockService;
use App\Domain\Users\UserStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithGuessGames;
use Tests\TestCase;

class GuessNumberTest extends TestCase
{
    use InteractsWithGuessGames, RefreshDatabase;

    private GuessNumberService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootGameTests();
        DB::table('coin_feature_prices')->where('feature_code', 'game_invitation')->update(['coin_cost' => 0]);
        $this->service = app(GuessNumberService::class);
    }

    private function type(): GameType
    {
        return GameType::GuessNumber;
    }

    // A deterministic test fixture only; no client entry point can set the secret.
    private function secret(GameSession $s, int $secret = 50): void
    {
        $state = $s->fresh()->state;
        $state['gn_secret'] = $secret;
        $s->update(['state' => $state]);
    }

    public function test_server_secret_within_default_range_and_private_view(): void
    {
        [$a, $b, $s] = $this->game();
        $this->assertGreaterThanOrEqual(1, $s->state['gn_secret']);
        $this->assertLessThanOrEqual(100, $s->state['gn_secret']);
        $this->assertSame($a->id, $s->state['gn_turn_user_id']);
        $v = $this->service->view($a, $s);
        $this->assertSame(['phase', 'turn', 'your_turn', 'min', 'max'], array_keys($v));
        $this->assertSame($v, app(GameService::class)->question($s));
        $this->assertArrayNotHasKey('gn_secret', $v);
    }

    public function test_configurable_range_is_snapshotted_and_secret_not_regenerated(): void
    {
        config(['guess_number.min' => 200, 'guess_number.max' => 210]);
        [$a, $b, $s] = $this->game();
        $this->assertGreaterThanOrEqual(200, $s->state['gn_secret']);
        $this->assertLessThanOrEqual(210, $s->state['gn_secret']);
        $before = $s->state;
        config(['guess_number.max' => 999]);
        $this->service->start($s);
        app(GameService::class)->accept($b, $s);
        $this->assertSame($before, $s->fresh()->state);
        $this->assertSame(210, $this->service->view($a, $s)['max']);
    }

    public function test_invalid_numeric_and_range_inputs_do_not_consume_turn(): void
    {
        [$a, $b, $s] = $this->game();
        foreach (['abc', '', ' ', '0', '101', '-1', '1.5', '1e1', '+2', '999999999999999999999999', '50 winner=1 xp=999', '{"secret":50}'] as $g) {
            $this->rejects(fn () => $this->service->guess($a, $s, $g, 'bad'));
            $this->assertSame($a->id, $s->fresh()->state['gn_turn_user_id']);
            $this->assertCount(0, $s->fresh()->state['gn_guesses']);
            $this->assertSame(1, $s->fresh()->current_round);
        }
    }

    public function test_only_current_player_can_guess_and_outsider_cannot_read(): void
    {
        [$a, $b, $s] = $this->game();
        $c = $this->user('C');
        $this->rejects(fn () => $this->service->guess($b, $s, '50', 'wrong-turn'));
        $this->rejects(fn () => $this->service->guess($c, $s, '50', 'outsider'));
        $this->rejects(fn () => $this->service->view($c, $s));
        $this->assertCount(0, $s->fresh()->state['gn_guesses']);
    }

    public function test_higher_lower_and_turn_switch_exactly_once(): void
    {
        [$a, $b, $s] = $this->game();
        $this->secret($s);
        $this->assertSame('Higher', $this->service->guess($a, $s, '25', 'first', 1)['hint']);
        $this->assertSame($b->id, $s->fresh()->state['gn_turn_user_id']);
        $this->assertSame('Lower', $this->service->guess($b, $s, '75', 'second', 2)['hint']);
        $this->assertSame($a->id, $s->fresh()->state['gn_turn_user_id']);
        $before = $s->fresh()->state;
        $this->assertTrue($this->service->guess($a, $s, '25', 'first', 1)['duplicate']);
        $this->assertSame($before, $s->fresh()->state);
        $this->assertSame(3, $s->fresh()->current_round);
    }

    public function test_stale_turn_rejected_but_same_number_on_new_turn_allowed(): void
    {
        [$a, $b, $s] = $this->game();
        $this->secret($s);
        $this->service->guess($a, $s, '25', 'one', 1);
        $this->service->guess($b, $s, '75', 'two', 2);
        $this->rejects(fn () => $this->service->guess($a, $s, '25', 'three', 1));
        $this->assertSame('Higher', $this->service->guess($a, $s, '25', 'three', 3)['hint']);
        $this->assertCount(3, $s->fresh()->state['gn_guesses']);
    }

    public function test_correct_guess_records_winner_loser_xp_ratings_streaks_once(): void
    {
        [$a, $b, $s] = $this->game();
        $this->secret($s);
        $this->service->guess($a, $s, '25', 'one');
        $done = $this->service->guess($b, $s, '50', 'two');
        $this->assertTrue($done['correct']);
        $this->assertTrue($done['completed']);
        $this->assertSame(GameStatus::Completed, $s->fresh()->status);
        $this->assertSame($b->id, $s->fresh()->state['gn_result']['winner_id']);
        $this->assertSame($a->id, $s->fresh()->state['gn_result']['loser_id']);
        $this->assertTrue($this->service->guess($b, $s, '50', 'two')['duplicate']);
        $this->rejects(fn () => $this->service->guess($a, $s, '50', 'late'));
        app(GameService::class)->settleCompleted($s, 'player_'.$a->id);
        foreach ([[$a, 10, 990, 0, 1], [$b, 25, 1020, 1, 0]] as [$u, $xp, $rating, $wins, $losses]) {
            $stats = app(GameService::class)->stats($u);
            $this->assertSame($xp, $stats->xp);
            $this->assertSame($rating, $stats->competitive_score);
            $this->assertSame($wins, $stats->wins);
            $this->assertSame($losses, $stats->losses);
            $this->assertSame($wins, $stats->current_streak);
            $this->assertSame($wins, $stats->best_streak);
            $this->assertSame(1, $stats->games_played);
            $this->assertDatabaseHas('game_participants', ['game_session_id' => $s->id, 'user_id' => $u->id, 'score' => $wins, 'xp_earned' => $xp]);
        }
        $this->assertDatabaseCount('game_rating_events', 1);
        $this->assertDatabaseHas('game_rating_events', ['game_session_id' => $s->id, 'user_id' => $b->id, 'delta' => 20, 'score_after' => 1020]);
        $this->assertDatabaseCount('coin_transactions', 0);
    }

    public function test_creator_win_does_not_also_mark_opponent_with_id_two_as_winner(): void
    {
        [$a, $b, $s] = $this->game();
        $this->secret($s);
        $this->service->guess($a, $s, '50', 'win');
        $this->assertSame(1, app(GameService::class)->stats($a)->wins);
        $this->assertSame(1, app(GameService::class)->stats($b)->losses);
        $this->assertSame(0, app(GameService::class)->stats($b)->wins);
        $this->assertDatabaseCount('game_rating_events', 1);
    }

    public function test_gold_and_wallet_balance_do_not_affect_gameplay_or_rewards(): void
    {
        [$a, $b, $s] = $this->game(false);
        app(GoldMembershipService::class)->activate($b, now()->addDay(), 'test');
        DB::table('wallets')->insertOrIgnore(['user_id' => $b->id, 'balance' => 50000, 'created_at' => now(), 'updated_at' => now()]);
        app(GameService::class)->accept($b, $s);
        $this->assertSame($a->id, $s->fresh()->state['gn_turn_user_id']);
        $this->secret($s);
        $this->service->guess($a, $s, '50', 'win');
        $this->assertSame(25, app(GameService::class)->stats($a)->xp);
        $this->assertSame(10, app(GameService::class)->stats($b)->xp);
        $this->assertSame(990, app(GameService::class)->stats($b)->competitive_score);
        $this->assertSame(50000, DB::table('wallets')->where('user_id', $b->id)->value('balance'));
        $this->assertDatabaseCount('coin_transactions', 0);
    }

    public function test_block_cancels_and_suppresses_delivery_and_rewards(): void
    {
        [$a, $b, $s] = $this->game();
        app(BlockService::class)->block($a, $b);
        $this->rejects(fn () => $this->service->guess($a, $s, (string) $s->state['gn_secret'], 'win'));
        $this->rejects(fn () => $this->service->view($b, $s));
        foreach (DB::table('social_outbox')->pluck('id') as $id) {
            (new DeliverSocialNotification($id))->handle($this->telegram);
        }
        $this->assertSame([], $this->telegram->sent);
        $this->assertSame(GameStatus::Cancelled, $s->fresh()->status);
        $this->assertDatabaseCount('game_player_stats', 0);
        $this->assertDatabaseCount('game_rating_events', 0);
    }

    public function test_expired_or_cancelled_games_cannot_settle_and_clear_context(): void
    {
        foreach ([GameStatus::Expired, GameStatus::Cancelled] as $status) {
            [$a, $b, $s] = $this->game();
            InteractionState::create(['user_id' => $a->id, 'mode' => 'game_guess_number', 'game_context' => ['session_id' => $s->id]]);
            if ($status === GameStatus::Expired) {
                $s->update(['expires_at' => now()->subSecond()]);
            } else {
                $s->update(['status' => $status]);
            }
            $this->rejects(fn () => $this->service->guess($a, $s, (string) $s->state['gn_secret'], 'win'));
            $this->rejects(fn () => app(GameService::class)->settleCompleted($s, 'player_'.$a->id));
            $this->assertSame($status, $s->fresh()->status);
            $this->assertDatabaseHas('interaction_states', ['user_id' => $a->id, 'mode' => 'menu', 'game_context' => null]);
        }
        $this->assertDatabaseCount('game_player_stats', 0);
        $this->assertDatabaseCount('game_rating_events', 0);
    }

    public function test_suspended_banned_and_deleted_opponents_prevent_progress(): void
    {
        foreach ([UserStatus::Suspended, UserStatus::Banned, UserStatus::Deleted] as $status) {
            [$a, $b, $s] = $this->game();
            $b->update(['status' => $status]);
            $this->rejects(fn () => $this->service->guess($a, $s, (string) $s->state['gn_secret'], 'win'));
            $this->assertSame(GameStatus::Cancelled, $s->fresh()->status);
        }
        $this->assertDatabaseCount('game_player_stats', 0);
    }

    public function test_inviter_expired_and_blocked_acceptance_are_safe(): void
    {
        [$a, $b, $s] = $this->game(false);
        $this->rejects(fn () => app(GameService::class)->accept($a, $s));
        $s->update(['expires_at' => now()->subSecond()]);
        app(GameService::class)->accept($b, $s);
        $this->assertSame(GameStatus::Expired, $s->fresh()->status);
        $this->assertArrayNotHasKey('gn_secret', $s->fresh()->state);
        [$a, $b, $s] = $this->game(false);
        app(BlockService::class)->block($a, $b);
        app(GameService::class)->accept($b, $s);
        $this->assertSame(GameStatus::Cancelled, $s->fresh()->status);
        $this->assertArrayNotHasKey('gn_secret', $s->fresh()->state);
    }

    public function test_generic_and_tampered_callbacks_cannot_override_game_state(): void
    {
        [$a, $b, $s] = $this->game();
        $this->secret($s);
        $this->rejects(fn () => app(GameService::class)->answer($a, $s, '50'));
        $this->rejects(fn () => app(GameService::class)->ensureRound($s));
        $this->rejects(fn () => app(GameService::class)->settleCompleted($s, 'player_'.$a->id));
        $before = $s->fresh()->state;
        foreach (['game_gn_answer_'.$s->id.'_1_50', 'game_answer_'.$s->id.'_50', 'game_gi_answer_'.$s->id.'_1_0', 'game_gn_open_'.$s->id.'_winner_999'] as $callback) {
            $this->send($a, 'd:0:'.$callback);
        }
        $this->assertSame($before, $s->fresh()->state);
        $this->assertDatabaseCount('game_player_stats', 0);
    }

    public function test_full_telegram_flow_numeric_hints_replay_final_results_and_privacy(): void
    {
        $a = $this->user('A');
        $b = $this->user('B');
        foreach ([$a, $b] as $u) {
            InteractionState::create(['user_id' => $u->id, 'mode' => 'chat']);
        }
        $this->send($a, 'd:0:game_play_guess_number_'.$b->id);
        $s = GameSession::firstOrFail();
        $this->send($b, 'd:0:game_accept_'.$s->id);
        $this->secret($s);
        $this->send($a, 'd:0:game_gn_open_'.$s->id);
        $this->send($a, 'not a number', false);
        $this->send($a, '101', false);
        $this->assertCount(0, $s->fresh()->state['gn_guesses']);
        $first = $this->send($a, '25', false);
        $this->send($a, '25', false, $first);
        $this->assertCount(1, $s->fresh()->state['gn_guesses']);
        $this->send($b, '75', false);
        $this->assertCount(2, $s->fresh()->state['gn_guesses']);
        $this->send($a, '25', false, $first);
        $this->assertCount(2, $s->fresh()->state['gn_guesses']);
        $this->assertStringNotContainsString('number was 50', json_encode($this->telegram->sent));
        $final = $this->send($a, '50', false);
        $this->send($a, '50', false, $final);
        $this->send($b, 'd:0:game_accept_'.$s->id);
        $this->assertSame(GameStatus::Completed, $s->fresh()->status);
        $this->assertSame(25, app(GameService::class)->stats($a)->xp);
        $this->assertSame(10, app(GameService::class)->stats($b)->xp);
        $this->assertSame(0, InteractionState::where('mode', 'game_guess_number')->count());
        $this->assertStringContainsString('Higher', $this->delivered($a));
        $this->assertStringContainsString('Higher', $this->delivered($b));
        $this->assertStringContainsString('Lower', $this->delivered($a));
        $this->assertStringContainsString('Correct!', $this->delivered($a));
        $this->assertStringContainsString('opponent wins', $this->delivered($b));
        $this->assertPrivateMessages($a, $b);
        $this->assertDatabaseCount('game_rating_events', 1);
        $this->assertDatabaseCount('coin_transactions', 0);
    }
}
