<?php

namespace Tests\Feature;

use App\Domain\Chat\ChatRequestService;
use App\Domain\Contacts\ContactService;
use App\Domain\Games\Badge;
use App\Domain\Games\DailyChallengeService;
use App\Domain\Games\GameClosureService;
use App\Domain\Games\GameProgressionService;
use App\Domain\Games\GameService;
use App\Domain\Games\GameSession;
use App\Domain\Games\GameStatus;
use App\Domain\Games\GameType;
use App\Domain\Games\GuessInterestService;
use App\Domain\Games\GuessNumberService;
use App\Domain\Games\RankingService;
use App\Domain\Games\SpeedQuizService;
use App\Domain\Games\ThisOrThatService;
use App\Domain\Games\TwoTruthsService;
use App\Domain\Memberships\GoldMembershipService;
use App\Domain\Moderation\ReportReason;
use App\Domain\Payments\InsufficientCoinsException;
use App\Domain\Payments\WalletService;
use App\Domain\Profiles\City;
use App\Domain\Telegram\GameHubInteraction;
use App\Domain\Telegram\InteractionState;
use App\Domain\Telegram\Jobs\DeliverSocialNotification;
use App\Domain\Users\BlockService;
use App\Domain\Users\User;
use App\Domain\Users\UserStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithGuessGames;
use Tests\TestCase;

class FinalPhase7Test extends TestCase
{
    use InteractsWithGuessGames, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootGameTests();
        DB::table('coin_feature_prices')->where('feature_code', 'game_invitation')->update(['coin_cost' => 0]);
    }

    private function type(): GameType
    {
        return GameType::GuessNumber;
    }

    private function ranked(string $name, int $score = 1000): User
    {
        $u = $this->user($name);
        app(GameService::class)->stats($u);
        DB::table('game_player_stats')->where('user_id', $u->id)->update(['competitive_score' => $score]);

        return $u;
    }

    private function event(User $u, int $delta, $at): void
    {
        DB::table('game_rating_events')->insert(['user_id' => $u->id, 'delta' => $delta, 'score_after' => 1000 + $delta, 'created_at' => $at]);
    }

    private function ui(User $u, string $value): string
    {
        return app(GameHubInteraction::class)->handle($u, $value)[0]['parameters']['text'];
    }

    private function completed(GameType $type = GameType::GuessNumber): array
    {
        $a = $this->user('A');
        $b = $this->user('B');
        $g = app(GameService::class);
        config(['guess_interest.questions_per_player' => 1, 'speed_quiz.questions_per_match' => 1, 'social.this_or_that_questions' => 1]);
        $s = $g->invite($a, $b, $type);
        $g->accept($b, $s);
        $s = $s->fresh();
        if ($type === GameType::GuessNumber) {
            app(GuessNumberService::class)->guess($a, $s, (string) $s->state['gn_secret'], 'fixture');
        }
        if ($type === GameType::GuessInterest) {
            app(GuessInterestService::class)->answer($a, $s, 0);
            app(GuessInterestService::class)->answer($b, $s->fresh(), 0);
        }
        if ($type === GameType::RockPaperScissors) {
            for ($i = 0; $i < 2; $i++) {
                $g->answer($b, $s->fresh(), 'scissors');
                $g->answer($a, $s->fresh(), 'rock');
            }
        }
        if ($type === GameType::SpeedQuiz || $type === GameType::ThisOrThat) {
            $svc = app($type === GameType::SpeedQuiz ? SpeedQuizService::class : ThisOrThatService::class);
            $q = $svc->question($s);
            $svc->answer($a, $s, $q['options'][0]);
            $svc->answer($b, $s->fresh(), $q['options'][0]);
        }
        if ($type === GameType::TwoTruthsOneLie) {
            $svc = app(TwoTruthsService::class);
            foreach ([[$a, $b], [$b, $a]] as [$creator, $guesser]) {
                foreach (['I play piano', 'I climb mountains', 'I paint portraits'] as $text) {
                    $svc->addStatement($creator, $s->fresh(), $text);
                }
                $svc->selectLie($creator, $s->fresh(), 1);
                $svc->guess($guesser, $s->fresh(), 0);
            }
        }
        $this->assertSame(GameStatus::Completed, $s->fresh()->status);

        return [$a, $b, $s->fresh()];
    }

    public function test_all_time_orders_by_current_rating(): void
    {
        $a = $this->ranked('A', 1000);
        $b = $this->ranked('B', 2400);
        $r = app(RankingService::class)->leaderboard($a);
        $this->assertSame(['B', 'A'], array_column($r['rows'], 'display_name'));
        $this->assertSame([2400, 1000], array_column($r['rows'], 'score'));
        $this->assertSame('Diamond', $r['rows'][0]['tier']);
        $this->assertSame([1, 2], array_column($r['rows'], 'position'));
    }

    public static function periods(): array
    {
        return [['daily'], ['weekly'], ['monthly']];
    }

    #[DataProvider('periods')]
    public function test_period_board_uses_net_delta_with_exclusive_end(string $period): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-18 12:00:00', 'UTC'));
        $a = $this->ranked('A', 2400);
        $b = $this->ranked('B', 1000);
        [$start, $end] = app(RankingService::class)->bounds($period);
        $this->event($a, 20, $start);
        $this->event($a, -10, now());
        $this->event($b, 15, now());
        $this->event($a, 999, CarbonImmutable::parse($start)->subSecond());
        $this->event($a, 999, $end);
        $r = app(RankingService::class)->leaderboard($a, $period);
        $this->assertSame(['B', 'A'], array_column($r['rows'], 'display_name'));
        $this->assertSame([15, 10], array_column($r['rows'], 'score'));
    }

    public function test_timezone_day_and_monday_week_boundaries(): void
    {
        config(['app.timezone' => 'Asia/Tehran']);
        $at = CarbonImmutable::parse('2026-09-21 00:00:00', 'Asia/Tehran');
        [$start, $end] = app(RankingService::class)->bounds('weekly', $at);
        $this->assertSame('2026-09-20T20:30:00+00:00', $start);
        $this->assertSame('2026-09-27T20:30:00+00:00', $end);
        $this->assertSame($start, app(RankingService::class)->bounds('daily', $at)[0]);
    }

    public function test_city_board_is_requester_city_only(): void
    {
        $a = $this->ranked('A');
        $b = $this->ranked('B', 2000);
        $b->profile->update(['city_id' => City::where('id', '!=', $a->profile->city_id)->first()->id]);
        $r = app(RankingService::class)->leaderboard($a, 'city');
        $this->assertSame(['A'], array_column($r['rows'], 'display_name'));
    }

    public function test_contacts_board_excludes_non_contacts_and_updates_after_removal(): void
    {
        $a = $this->ranked('A');
        $b = $this->ranked('B', 1500);
        $c = $this->ranked('C', 2000);
        app(ContactService::class)->add($a, $b);
        $this->assertSame(['B'], array_column(app(RankingService::class)->leaderboard($a, 'contacts')['rows'], 'display_name'));
        app(ContactService::class)->remove($a, $b);
        $this->assertSame([], app(RankingService::class)->leaderboard($a, 'contacts')['rows']);
    }

    public function test_own_rank_is_shown_outside_first_page(): void
    {
        config(['ranking.page_size' => 2]);
        $a = $this->ranked('A', 500);
        foreach (['B', 'C', 'D'] as $name) {
            $this->ranked($name, 1500);
        }
        $r = app(RankingService::class)->leaderboard($a);
        $this->assertCount(2, $r['rows']);
        $this->assertSame(4, $r['own']['position']);
        $this->assertStringContainsString('Your Rank: #4', $this->ui($a, 'game_board_all_1'));
    }

    public function test_pagination_has_stable_positions_without_duplicates(): void
    {
        config(['ranking.page_size' => 2]);
        $a = $this->ranked('A');
        foreach (['B', 'C', 'D', 'E'] as $n) {
            $this->ranked($n);
        }
        $one = app(RankingService::class)->leaderboard($a, 'all', 1);
        $two = app(RankingService::class)->leaderboard($a, 'all', 2);
        $this->assertSame([1, 2], array_column($one['rows'], 'position'));
        $this->assertSame([3, 4], array_column($two['rows'], 'position'));
        $this->assertSame(3, app(RankingService::class)->leaderboard($a, 'all', 999)['page']);
    }

    public function test_gold_and_wallet_do_not_affect_leaderboard_order(): void
    {
        $a = $this->ranked('A', 1000);
        $b = $this->ranked('B', 1200);
        $before = app(RankingService::class)->leaderboard($a);
        app(GoldMembershipService::class)->activate($a, now()->addDay(), 'test');
        app(WalletService::class)->wallet($a)->update(['balance' => 100000]);
        $this->assertSame($before, app(RankingService::class)->leaderboard($a));
        $this->assertSame('Silver', app(GameProgressionService::class)->stats($a)['rank']['tier']);
    }

    public function test_blocked_and_inactive_players_are_hidden(): void
    {
        $a = $this->ranked('A');
        $b = $this->ranked('B', 2000);
        $c = $this->ranked('C', 3000);
        app(BlockService::class)->block($b, $a);
        $c->update(['status' => UserStatus::Banned]);
        $this->assertSame(['A'], array_column(app(RankingService::class)->leaderboard($a)['rows'], 'display_name'));
    }

    public function test_guess_number_compound_event_includes_loser_net_rating(): void
    {
        [$a, $b, $s] = $this->completed();
        $r = app(RankingService::class)->leaderboard($b, 'daily');
        $this->assertSame([20, -10], array_column($r['rows'], 'score'));
        $this->assertSame(-10, $r['own']['score']);
        $this->assertDatabaseCount('game_rating_events', 1);
    }

    public function test_rating_floor_records_applied_delta_only(): void
    {
        [$a, $b, $s] = $this->game();
        app(GameService::class)->stats($b);
        DB::table('game_player_stats')->where('user_id', $b->id)->update(['competitive_score' => 3]);
        app(GuessNumberService::class)->guess($a, $s, (string) $s->state['gn_secret'], 'floor');
        $this->assertSame(-3, app(RankingService::class)->leaderboard($b, 'daily')['own']['score']);
        $this->assertStringContainsString('Rating change: -3', app(GameClosureService::class)->result($b, $s)['text']);
    }

    public static function ranks(): array
    {
        return [[1840, 'Gold II', 2000, 160, 46], [2000, 'Gold I', 2200, 200, 0], [0, 'Bronze', 1000, 1000, 0], [2500, 'Diamond', null, 0, 100]];
    }

    #[DataProvider('ranks')]
    public function test_rank_current_next_remaining_and_top(int $rating, string $tier, ?int $next, int $remaining, int $progress): void
    {
        $r = app(RankingService::class)->rank($rating);
        $this->assertSame($tier, $r['tier']);
        $this->assertSame($next, $r['next_threshold']);
        $this->assertSame($remaining, $r['remaining']);
        $this->assertSame($progress, $r['progress']);
    }

    public static function statsFields(): array
    {
        return [['Games played: 10'], ['Wins: 6'], ['Losses: 2'], ['Draws: 2'], ['Win rate: 60%'], ['Current win streak: 3'], ['Best win streak: 5'], ['XP: 170'], ['Rating: 1840'], ['Gold II'], ['160 points remaining']];
    }

    #[DataProvider('statsFields')]
    public function test_stats_screen_cached_values(string $expected): void
    {
        $a = $this->ranked('A', 1840);
        DB::table('game_player_stats')->where('user_id', $a->id)->update(['games_played' => 10, 'wins' => 6, 'losses' => 2, 'draws' => 2, 'current_streak' => 3, 'best_streak' => 5, 'xp' => 170]);
        $this->assertStringContainsString($expected, $this->ui($a, 'game_stats'));
    }

    public function test_first_win_badge_is_awarded_and_displayed_with_date_icon_description(): void
    {
        [$a] = $this->completed();
        $text = $this->ui($a, 'game_badges');
        $this->assertStringContainsString('First Win', $text);
        $this->assertStringContainsString('Win your first game', $text);
        $this->assertStringContainsString('Earned: '.now()->toDateString(), $text);
        $this->assertNotSame('??', app(GameProgressionService::class)->badges($a)[0]['icon']);
    }

    public function test_badge_awarding_is_idempotent(): void
    {
        [$a] = $this->completed();
        app(GameProgressionService::class)->award($a);
        app(GameProgressionService::class)->award($a);
        $this->assertSame(1, DB::table('user_badges')->where('user_id', $a->id)->count());
    }

    public function test_five_win_streak_badge_awards_at_threshold(): void
    {
        $a = $this->ranked('A');
        DB::table('game_player_stats')->where('user_id', $a->id)->update(['best_streak' => 4]);
        app(GameProgressionService::class)->award($a);
        $badge = Badge::where('code', 'five_streak')->firstOrFail();
        $this->assertDatabaseMissing('user_badges', ['user_id' => $a->id, 'badge_id' => $badge->id]);
        DB::table('game_player_stats')->where('user_id', $a->id)->update(['best_streak' => 5]);
        app(GameProgressionService::class)->award($a);
        $this->assertDatabaseHas('user_badges', ['user_id' => $a->id, 'badge_id' => $badge->id]);
    }

    public function test_wins_and_games_badges_have_idempotent_thresholds(): void
    {
        $a = $this->ranked('A');
        DB::table('game_player_stats')->where('user_id', $a->id)->update(['wins' => 50, 'games_played' => 100]);
        app(GameProgressionService::class)->award($a);
        app(GameProgressionService::class)->award($a);
        $this->assertCount(3, app(GameProgressionService::class)->badges($a));
    }

    public static function gameTypes(): array
    {
        return array_map(fn ($type) => [$type], array_values(array_filter(GameType::cases(), fn ($type) => $type !== GameType::TruthOrDare)));
    }

    #[DataProvider('gameTypes')]
    public function test_all_games_have_consistent_postgame_result_and_actions(GameType $type): void
    {
        [$a, $b, $s] = $this->completed($type);
        $r = app(GameClosureService::class)->result($a, $s);
        $this->assertStringContainsString('XP earned:', $r['text']);
        $this->assertStringContainsString('Current rank:', $r['text']);
        $this->assertCount(6, $r['keyboard']);
        $competitive = in_array($type, [GameType::RockPaperScissors, GameType::SpeedQuiz, GameType::GuessNumber], true);
        $this->assertSame($competitive, str_contains($r['text'], 'Rating change:'));
        foreach ([$a, $b] as $u) {
            $this->assertSame(1, DB::table('social_outbox')->where('source_type', 'game_result')->where('recipient_user_id', $u->id)->where('source_id', $s->id)->count());
        }
    }

    public function test_rematch_creates_new_session_and_preserves_history(): void
    {
        [$a, $b, $s] = $this->completed();
        $before = app(GameService::class)->stats($a);
        $new = app(GameClosureService::class)->rematch($a, $s);
        $this->assertNotSame($s->id, $new->id);
        $this->assertSame(GameStatus::Waiting, $new->status);
        $this->assertSame(GameStatus::Completed, $s->fresh()->status);
        $this->assertEquals($before, app(GameService::class)->stats($a));
    }

    public function test_duplicate_rematch_from_either_player_reuses_one_invitation(): void
    {
        [$a, $b, $s] = $this->completed();
        $one = app(GameClosureService::class)->rematch($a, $s);
        $two = app(GameClosureService::class)->rematch($b, $s);
        $this->assertSame($one->id, $two->id);
        $this->assertDatabaseCount('game_sessions', 2);
    }

    public function test_block_prevents_rematch(): void
    {
        [$a, $b, $s] = $this->completed();
        app(BlockService::class)->block($b, $a);
        $this->rejects(fn () => app(GameClosureService::class)->rematch($a, $s));
        $this->assertDatabaseCount('game_sessions', 1);
    }

    public function test_inactive_account_prevents_rematch(): void
    {
        [$a, $b, $s] = $this->completed();
        $b->update(['status' => UserStatus::Suspended]);
        $this->rejects(fn () => app(GameClosureService::class)->rematch($a, $s));
    }

    public function test_postgame_contact_uses_existing_service_and_duplicate_safe(): void
    {
        [$a, $b, $s] = $this->completed();
        $this->assertTrue(app(GameClosureService::class)->contact($a, $s));
        $this->assertFalse(app(GameClosureService::class)->contact($a, $s));
        $this->assertDatabaseHas('user_contacts', ['user_id' => $a->id, 'contact_user_id' => $b->id]);
        $this->assertDatabaseCount('user_contacts', 1);
    }

    public function test_block_prevents_postgame_contact(): void
    {
        [$a, $b, $s] = $this->completed();
        app(BlockService::class)->block($b, $a);
        $this->rejects(fn () => app(GameClosureService::class)->contact($a, $s));
        $this->assertDatabaseCount('user_contacts', 0);
    }

    public function test_chat_action_creates_normal_request_without_conversation_or_charge(): void
    {
        [$a, $b, $s] = $this->completed();
        $r = app(GameClosureService::class)->chat($a, $s);
        $this->assertSame($a->id, $r->requester_user_id);
        $this->assertSame($b->id, $r->recipient_user_id);
        $this->assertDatabaseCount('conversations', 0);
        $this->assertDatabaseCount('coin_transactions', 0);
        $this->assertSame($r->id, app(GameClosureService::class)->chat($a, $s)->id);
        $this->assertDatabaseCount('chat_requests', 1);
    }

    public function test_postgame_chat_acceptance_enforces_existing_paid_rule(): void
    {
        [$a, $b, $s] = $this->completed();
        $r = app(GameClosureService::class)->chat($a, $s);
        try {
            app(ChatRequestService::class)->accept($b, $r);
            $this->fail('Expected insufficient coins');
        } catch (InsufficientCoinsException $e) {
            $this->assertDatabaseCount('conversations', 0);
        }
        app(WalletService::class)->wallet($a)->update(['balance' => 10]);
        app(ChatRequestService::class)->accept($b, $r);
        $this->assertSame(8, app(WalletService::class)->wallet($a)->fresh()->balance);
        $this->assertDatabaseCount('conversations', 1);
        $this->assertDatabaseCount('coin_transactions', 1);
        app(ChatRequestService::class)->accept($b, $r);
        $this->assertDatabaseCount('coin_transactions', 1);
    }

    public function test_first_interest_is_private_and_sends_no_opponent_notification(): void
    {
        [$a, $b, $s] = $this->completed();
        $before = DB::table('social_outbox')->count();
        $this->assertFalse(app(GameClosureService::class)->interest($a, $s));
        $this->assertDatabaseHas('game_social_intents', ['game_session_id' => $s->id, 'user_id' => $a->id]);
        $this->assertSame($before, DB::table('social_outbox')->count());
        $this->assertArrayNotHasKey('mutual_match_at', $s->fresh()->state);
        $this->assertStringNotContainsString('saved privately', app(GameClosureService::class)->result($b, $s)['text']);
    }

    public function test_one_sided_intent_does_not_leak_into_other_public_views(): void
    {
        [$a, $b, $s] = $this->completed();
        $before = [$this->ui($b, 'game_stats'), $this->ui($b, 'game_board_all_1'), $this->ui($b, 'game_badges'), app(ContactService::class)->list($b)->toArray()];
        app(GameClosureService::class)->interest($a, $s);
        $this->assertSame($before, [$this->ui($b, 'game_stats'), $this->ui($b, 'game_board_all_1'), $this->ui($b, 'game_badges'), app(ContactService::class)->list($b)->toArray()]);
    }

    public function test_second_interest_creates_mutual_state_and_two_queued_notices_once(): void
    {
        [$a, $b, $s] = $this->completed();
        $c = app(GameClosureService::class);
        $c->interest($a, $s);
        $this->assertTrue($c->interest($b, $s));
        $at = $s->fresh()->state['mutual_match_at'];
        $this->assertTrue($c->interest($b, $s));
        $this->assertTrue($c->interest($a, $s));
        $this->assertSame($at, $s->fresh()->state['mutual_match_at']);
        $this->assertDatabaseCount('game_social_intents', 2);
        $rows = DB::table('social_outbox')->where('source_type', 'game_mutual')->get();
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $payload = Crypt::decryptString($row->payload);
            $this->assertStringContainsString('Both of you', $payload);
            $this->assertStringContainsString('game_post_chat_', $payload);
        }
        $this->assertDatabaseCount('conversations', 0);
        $this->assertDatabaseCount('chat_requests', 0);
        $this->assertDatabaseCount('coin_transactions', 0);
    }

    public function test_mutual_notices_are_suppressed_after_block(): void
    {
        [$a, $b, $s] = $this->completed();
        $c = app(GameClosureService::class);
        $c->interest($a, $s);
        $c->interest($b, $s);
        app(BlockService::class)->block($a, $b);
        foreach (DB::table('social_outbox')->where('source_type', 'game_mutual')->pluck('id') as $id) {
            (new DeliverSocialNotification($id))->handle($this->telegram);
        }
        $this->assertSame([], $this->telegram->sent);
    }

    public function test_postgame_report_uses_existing_report_table_and_safe_reference(): void
    {
        [$a, $b, $s] = $this->completed();
        app(GameClosureService::class)->report($a, $s, ReportReason::Spam);
        app(GameClosureService::class)->report($a, $s, ReportReason::Spam);
        $this->assertDatabaseCount('reports', 1);
        $r = DB::table('reports')->first();
        $this->assertSame($a->id, $r->reporter_user_id);
        $this->assertSame($b->id, $r->reported_user_id);
        $this->assertStringContainsString('Game session #'.$s->id, $r->description);
        $this->assertStringNotContainsString('gn_secret', $r->description);
    }

    public function test_invalid_actor_and_unfinished_session_cannot_use_postgame_actions(): void
    {
        [$a, $b, $s] = $this->game();
        $c = $this->user('C');
        $closure = app(GameClosureService::class);
        foreach ([$a, $c] as $u) {
            $this->rejects(fn () => $closure->rematch($u, $s));
            $this->rejects(fn () => $closure->contact($u, $s));
            $this->rejects(fn () => $closure->chat($u, $s));
            $this->rejects(fn () => $closure->interest($u, $s));
            $this->rejects(fn () => $closure->report($u, $s, ReportReason::Spam));
        }
    }

    public function test_daily_challenge_question_answer_and_result_via_webhook(): void
    {
        $a = $this->user('A');
        $this->send($a, 'd:0:game_daily');
        $q = app(DailyChallengeService::class)->question($a);
        $this->assertStringContainsString($q['question'], $this->delivered($a));
        $this->assertArrayNotHasKey('answer', $q);
        $this->send($a, 'd:0:game_daily_answer_'.str_replace('-', '', $q['date']).'_0');
        $this->assertStringContainsString('Daily Challenge complete', $this->delivered($a));
        $this->assertSame(10, app(GameService::class)->stats($a)->xp);
        $this->assertPrivateMessages($a);
    }

    public function test_daily_completion_xp_once_and_no_coins_or_rating(): void
    {
        $a = $this->user('A');
        $svc = app(DailyChallengeService::class);
        $q = $svc->question($a);
        $svc->answer($a, $q['date'], 0);
        $r = $svc->answer($a, $q['date'], 1);
        $this->assertTrue($r['duplicate']);
        $this->assertSame(10, app(GameService::class)->stats($a)->xp);
        $this->assertDatabaseCount('daily_challenge_completions', 1);
        $this->assertDatabaseCount('coin_transactions', 0);
        $this->assertDatabaseCount('game_rating_events', 0);
    }

    public function test_gold_does_not_change_daily_reward_or_question(): void
    {
        $a = $this->user('A');
        $b = $this->user('B');
        app(GoldMembershipService::class)->activate($b, now()->addDay(), 'test');
        $svc = app(DailyChallengeService::class);
        $q = $svc->question($a);
        $this->assertSame($q, $svc->question($b));
        foreach ([$a, $b] as $u) {
            $this->assertSame(10, $svc->answer($u, $q['date'], 0)['xp']);
        }
    }

    public function test_daily_correct_and_incorrect_answers_are_server_validated(): void
    {
        $a = $this->user('A');
        $b = $this->user('B');
        $svc = app(DailyChallengeService::class);
        $q = $svc->question($a);
        $p = json_decode(DB::table('daily_game_questions')->value('prompt'), true);
        $correct = array_search($p['answer'], $p['options'], true);
        $this->assertTrue($svc->answer($a, $q['date'], $correct)['correct']);
        $this->assertFalse($svc->answer($b, $q['date'], ($correct + 1) % count($q['options']))['correct']);
    }

    public function test_daily_old_date_and_invalid_index_cannot_reward(): void
    {
        $a = $this->user('A');
        $svc = app(DailyChallengeService::class);
        $q = $svc->question($a);
        $this->rejects(fn () => $svc->answer($a, now()->subDay()->toDateString(), 0));
        $this->rejects(fn () => $svc->answer($a, $q['date'], 999));
        $this->assertDatabaseCount('daily_game_answers', 0);
        $this->assertDatabaseCount('daily_challenge_completions', 0);
    }

    public function test_old_complete_button_cannot_bypass_daily_answer(): void
    {
        $a = $this->user('A');
        $this->send($a, 'd:0:game_daily_complete');
        $this->assertFalse(app(GameService::class)->completeDailyChallenge($a));
        $this->assertDatabaseCount('daily_challenge_completions', 0);
    }

    public function test_daily_new_application_day_allows_one_new_reward(): void
    {
        config(['app.timezone' => 'Asia/Tehran']);
        $this->travelTo(CarbonImmutable::parse('2026-09-18 20:29:00', 'UTC'));
        $a = $this->user('A');
        $svc = app(DailyChallengeService::class);
        $q = $svc->question($a);
        $svc->answer($a, $q['date'], 0);
        $this->travel(2)->minutes();
        $q2 = $svc->question($a);
        $this->assertNotSame($q['date'], $q2['date']);
        $svc->answer($a, $q2['date'], 0);
        $this->assertSame(20, app(GameService::class)->stats($a)->xp);
    }

    public static function staleStatuses(): array
    {
        return [[GameStatus::Waiting], [GameStatus::Accepted], [GameStatus::Active]];
    }

    #[DataProvider('staleStatuses')]
    public function test_cleanup_expires_stale_sessions_without_rewards(GameStatus $status): void
    {
        [$a, $b, $s] = $this->game(false);
        $s->update(['status' => $status, 'expires_at' => now()->subSecond()]);
        $this->artisan('games:cleanup')->assertExitCode(0);
        $this->assertSame(GameStatus::Expired, $s->fresh()->status);
        $this->assertDatabaseCount('game_player_stats', 0);
        $this->assertDatabaseCount('game_rating_events', 0);
    }

    public function test_cleanup_clears_game_context_and_is_idempotent(): void
    {
        [$a, $b, $s] = $this->game();
        $s->update(['expires_at' => now()->subSecond()]);
        foreach ([$a, $b] as $u) {
            InteractionState::create(['user_id' => $u->id, 'mode' => 'game_guess_number', 'game_context' => ['session_id' => $s->id, 'turn' => 1]]);
        }
        $this->assertSame(1, app(GameService::class)->expireStale());
        $this->assertSame(0, app(GameService::class)->expireStale());
        $this->assertSame(2, InteractionState::where('mode', 'menu')->whereNull('game_context')->count());
    }

    public function test_cleanup_handles_abandoned_sessions_without_deadline(): void
    {
        [$a, $b, $s] = $this->game();
        $s->update(['expires_at' => null, 'updated_at' => now()->subDays(2)]);
        $this->assertSame(1, app(GameService::class)->expireStale());
        $this->assertSame(GameStatus::Expired, $s->fresh()->status);
    }

    public function test_gold_and_wallet_do_not_change_competitive_rewards(): void
    {
        [$a, $b, $s] = $this->game();
        app(GoldMembershipService::class)->activate($b, now()->addDay(), 'test');
        app(WalletService::class)->wallet($b)->update(['balance' => 99999]);
        app(GuessNumberService::class)->guess($a, $s, (string) $s->state['gn_secret'], 'win');
        $this->assertSame(25, app(GameService::class)->stats($a)->xp);
        $this->assertSame(10, app(GameService::class)->stats($b)->xp);
        $this->assertSame(990, app(GameService::class)->stats($b)->competitive_score);
        $this->assertSame(99999, app(WalletService::class)->wallet($b)->fresh()->balance);
        $this->assertDatabaseCount('coin_transactions', 0);
    }

    public function test_leaderboard_stats_results_hide_protected_identity_and_location(): void
    {
        [$a, $b, $s] = $this->completed();
        foreach (['game_stats', 'game_rank', 'game_badges', 'game_board_all_1', 'game_post_result_'.$s->id] as $action) {
            $this->send($a, 'd:0:'.$action);
        }
        $this->assertPrivateMessages($a, $b);
        $a->profile->update(['display_name' => $a->telegram_username]);
        $this->assertSame('Player', app(RankingService::class)->leaderboard($a)['rows'][0]['display_name']);
    }

    public static function modes(): array
    {
        return [['game_guess_number'], ['game_guess_interest'], ['game_two_truths'], ['game_speed_quiz'], ['game_this_or_that'], ['game_rps']];
    }

    #[DataProvider('modes')]
    public function test_start_and_back_safely_exit_game_states(string $mode): void
    {
        $a = $this->user('A');
        $i = InteractionState::create(['user_id' => $a->id, 'mode' => $mode, 'game_context' => ['session_id' => 123]]);
        $this->send($a, '/start', false);
        $this->assertNull($i->fresh()->game_context);
        $this->assertNotSame($mode, $i->fresh()->mode);
        $i->update(['mode' => $mode, 'game_context' => ['session_id' => 123]]);
        $this->send($a, 'd:0:games');
        $this->assertSame('menu', $i->fresh()->mode);
        $this->assertNull($i->fresh()->game_context);
    }

    public function test_real_telegram_rps_flow_to_postgame_and_social_actions(): void
    {
        $a = $this->user('A');
        $b = $this->user('B');
        $this->send($a, 'd:0:game_play_rock_paper_scissors_'.$b->id);
        $s = GameSession::firstOrFail();
        $this->send($b, 'd:0:game_accept_'.$s->id);
        for ($round = 1; $round <= 2; $round++) {
            $this->send($b, 'd:0:game_rps_answer_'.$s->id.'_'.$round.'_scissors');
            $this->send($a, 'd:0:game_rps_answer_'.$s->id.'_'.$round.'_rock');
        }
        $this->assertSame(GameStatus::Completed, $s->fresh()->status);
        foreach (['contact', 'interest'] as $action) {
            $this->send($a, 'd:0:game_post_'.$action.'_'.$s->id);
        }
        $this->send($b, 'd:0:game_post_interest_'.$s->id);
        $this->send($a, 'd:0:game_post_report_'.$s->id);
        $this->send($a, 'd:0:game_post_reason_'.$s->id.'_spam');
        $this->send($a, 'd:0:game_post_chat_'.$s->id);
        $this->send($a, 'd:0:game_post_rematch_'.$s->id);
        $this->assertDatabaseCount('user_contacts', 1);
        $this->assertDatabaseCount('reports', 1);
        $this->assertDatabaseCount('chat_requests', 1);
        $this->assertDatabaseCount('conversations', 0);
        $this->assertDatabaseCount('game_sessions', 2);
        $this->assertStringContainsString('Both of you', $this->delivered($b));
        $this->assertPrivateMessages($a, $b);
    }

    public function test_weekly_top_ten_badge_uses_completed_week_and_awards_once(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-21 12:00:00', 'UTC'));
        $players = [];
        foreach (range(1, 12) as $i) {
            $u = $this->ranked('Player '.$i);
            $players[] = $u;
            $this->event($u, $i, now()->subWeek());
        }
        $this->event($players[0], 99999, now());
        $this->assertSame(10, app(GameProgressionService::class)->awardWeekly());
        $this->assertSame(0, app(GameProgressionService::class)->awardWeekly());
        $badge = Badge::where('code', 'top_ten_weekly')->firstOrFail();
        $this->assertDatabaseMissing('user_badges', ['user_id' => $players[0]->id, 'badge_id' => $badge->id]);
        $this->assertDatabaseHas('user_badges', ['user_id' => $players[11]->id, 'badge_id' => $badge->id]);
        $this->assertDatabaseCount('coin_transactions', 0);
    }

    public function test_weekly_badge_excludes_zero_and_negative_net_gain(): void
    {
        $a = $this->ranked('A');
        $b = $this->ranked('B');
        $this->event($a, -10, now()->subWeek());
        $this->assertSame(0, app(GameProgressionService::class)->awardWeekly());
        $this->assertDatabaseCount('user_badges', 0);
    }

    public function test_quiz_and_rps_achievement_thresholds(): void
    {
        $a = $this->ranked('A');
        $b = $this->ranked('B');
        foreach ([GameType::SpeedQuiz, GameType::RockPaperScissors] as $type) {
            foreach (range(1, 10) as $i) {
                $s = GameSession::create(['game_type' => $type, 'status' => GameStatus::Completed, 'created_by' => $a->id, 'state' => ['winner_user_id' => $a->id]]);
                $s->participants()->attach([$a->id, $b->id]);
            }
        }
        app(GameProgressionService::class)->award($a);
        foreach (['quiz_master', 'rps_champion'] as $code) {
            $this->assertDatabaseHas('user_badges', ['user_id' => $a->id, 'badge_id' => Badge::where('code', $code)->value('id')]);
        }
    }

    public function test_opponent_and_contact_pickers_are_real_private_and_gold_neutral(): void
    {
        $a = $this->user('A');
        $b = $this->user('B');
        $c = $this->user('C');
        app(ContactService::class)->add($a, $b);
        $this->send($a, 'd:0:game_type_contacts_rock_paper_scissors');
        $last = collect($this->telegram->sent)->filter(fn ($m) => isset($m['parameters']['text']))->last();
        $keyboard = json_encode($last['parameters']['reply_markup']);
        $this->assertStringContainsString('profile_'.$b->id.'_game_pick_contacts_rock_paper_scissors_1', $keyboard);
        $this->assertStringNotContainsString('profile_'.$c->id.'_game_pick_contacts_rock_paper_scissors_1', $keyboard);
        $revision = (int) DB::table('registration_states')->where('user_id', $a->id)->value('revision');
        $this->send($a, 'd:'.$revision.':game_type_opponent_rock_paper_scissors');
        $before = collect($this->telegram->sent)->filter(fn ($m) => isset($m['parameters']['text']))->last()['parameters']['reply_markup'];
        app(GoldMembershipService::class)->activate($c, now()->addDay(), 'test');
        $revision = (int) DB::table('registration_states')->where('user_id', $a->id)->value('revision');
        $this->send($a, 'd:'.$revision.':game_type_opponent_rock_paper_scissors');
        $after = collect($this->telegram->sent)->filter(fn ($m) => isset($m['parameters']['text']))->last()['parameters']['reply_markup'];
        $this->assertSame(array_column(array_merge(...$before['inline_keyboard']), 'text'), array_column(array_merge(...$after['inline_keyboard']), 'text'));
        $this->assertPrivateMessages($a, $b, $c);
    }

    public static function sharedQuestionGames(): array
    {
        return [[GameType::SpeedQuiz, 'speed'], [GameType::ThisOrThat, 'this']];
    }

    #[DataProvider('sharedQuestionGames')]
    public function test_real_telegram_shared_question_games_reach_unified_result(GameType $type, string $code): void
    {
        config(['speed_quiz.questions_per_match' => 1, 'social.this_or_that_questions' => 1]);
        $a = $this->user('A');
        $b = $this->user('B');
        $this->send($a, 'd:0:game_play_'.$type->value.'_'.$b->id);
        $s = GameSession::firstOrFail();
        $this->send($b, 'd:0:game_accept_'.$s->id);
        $this->send($a, 'd:0:game_open_'.$s->id);
        foreach ([$a, $b] as $u) {
            $this->send($u, 'd:0:game_'.$code.'_answer_'.$s->id.'_1_0');
        }
        $this->assertSame(GameStatus::Completed, $s->fresh()->status);
        foreach ([$a, $b] as $u) {
            $this->assertStringContainsString('Current rank:', $this->delivered($u));
        }
        $this->assertPrivateMessages($a, $b);
    }

    public function test_nonexistent_game_buttons_do_not_poison_webhook_processing(): void
    {
        $a = $this->user('A');
        foreach (['game_open_999999', 'game_rps_answer_999999_1_rock', 'game_post_result_999999', 'game_post_reason_999999_spam'] as $action) {
            $this->send($a, 'd:0:'.$action);
        }
        $this->assertSame(0, DB::table('telegram_updates')->whereNull('processed_at')->count());
        $this->assertStringContainsString('unavailable', $this->delivered($a));
    }

    public function test_legacy_guess_number_ledger_is_enriched_without_reawarding(): void
    {
        [$a, $b, $s] = $this->completed();
        $before = app(GameService::class)->stats($b);
        $migration = require database_path('migrations/2026_09_18_000018_complete_game_closure.php');
        $migration->down();
        $migration->up();
        $deltas = json_decode(DB::table('game_rating_events')->where('game_session_id', $s->id)->value('participant_deltas'), true);
        $this->assertSame([$a->id => 20, $b->id => -10], $deltas);
        $this->assertEquals($before, app(GameService::class)->stats($b));
        $this->assertSame(-10, app(RankingService::class)->leaderboard($b, 'daily')['own']['score']);
    }

    public function test_per_game_breakdown_is_cached_and_refreshes_after_completion(): void
    {
        [$a, $b] = $this->completed();
        DB::enableQueryLog();
        $progress = app(GameProgressionService::class);
        $first = $progress->stats($a);
        $second = $progress->stats($a);
        $aggregates = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'COUNT(*) as played'));
        $this->assertCount(1, $aggregates);
        $this->assertSame($first['per_game'], $second['per_game']);
        DB::disableQueryLog();
        $s = app(GameService::class)->invite($a, $b, GameType::GuessNumber);
        app(GameService::class)->accept($b, $s);
        $s = $s->fresh();
        app(GuessNumberService::class)->guess($a, $s, (string) $s->state['gn_secret'], 'second');
        $this->assertSame(2, (int) $progress->stats($a)['per_game']['guess_number']);
    }
}
