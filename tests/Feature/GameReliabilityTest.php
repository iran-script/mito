<?php

namespace Tests\Feature;

use App\Domain\Chat\ConversationService;
use App\Domain\Games\GameMatchmakingService;
use App\Domain\Games\GameService;
use App\Domain\Games\GameSession;
use App\Domain\Games\GameStatus;
use App\Domain\Games\GameType;
use App\Domain\Profiles\RegistrationState;
use App\Domain\Telegram\HttpTelegramClient;
use App\Domain\Telegram\InteractionState;
use App\Domain\Telegram\Jobs\DeliverMessage;
use App\Domain\Telegram\Jobs\ProcessUpdate;
use App\Domain\Telegram\PermanentTelegramFailure;
use App\Domain\Telegram\TelegramClient;
use App\Domain\Users\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithGuessGames;
use Tests\TestCase;

class GameReliabilityTest extends TestCase
{
    use InteractsWithGuessGames, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootGameTests();
        app()->setLocale('fa');
    }

    private function action(User $user, string $action): string
    {
        return 'd:'.RegistrationState::where('user_id', $user->id)->value('revision').':'.$action;
    }

    private function search(User $user, GameType $type = GameType::RockPaperScissors): array
    {
        return app(GameMatchmakingService::class)->search($user, $type, 'male');
    }

    public function test_opening_games_twice_only_renders_menu_without_a_queue_row(): void
    {
        $u = $this->user('A');
        $this->send($u, __('Games'), false);
        $this->send($u, __('Games'), false);
        $this->assertDatabaseCount('game_matchmaking_queue', 0);
        $this->assertStringContainsString(__('Choose one of these games:'), $this->delivered($u));
        $this->assertDatabaseCount('telegram_updates', 2);
    }

    public function test_queue_entry_requires_the_random_opponent_gender_step(): void
    {
        $u = $this->user('A');
        $this->send($u, __('Games'), false);
        $this->send($u, $this->action($u, 'game_select_rock_paper_scissors'));
        $this->send($u, $this->action($u, 'game_opponent_mode_random_rock_paper_scissors'));
        $this->assertDatabaseCount('game_matchmaking_queue', 0);
        $this->send($u, $this->action($u, 'game_random_gender_rock_paper_scissors_male'));
        $this->assertDatabaseCount('game_matchmaking_queue', 1);
    }

    public function test_repeated_same_callback_is_safe_and_stops_the_spinner(): void
    {
        $u = $this->user('A');
        $button = $this->action($u, 'game_random_gender_rock_paper_scissors_male');
        $this->send($u, $button);
        $this->send($u, $button);
        $this->assertDatabaseCount('game_matchmaking_queue', 1);
        $this->assertStringContainsString('این گزینه دیگه فعال نیست.', $this->delivered($u));
        $this->assertCount(2, array_filter($this->telegram->sent, fn ($m) => $m['method'] === 'answerCallbackQuery'));
        $this->assertNotEmpty(array_filter($this->telegram->sent, fn ($m) => $m['method'] === 'editMessageReplyMarkup'));
    }

    public function test_duplicate_webhook_does_not_repeat_domain_or_delivery_work(): void
    {
        $u = $this->user('A');
        $button = $this->action($u, 'game_random_gender_rock_paper_scissors_male');
        $this->send($u, $button, true, 999);
        $sent = count($this->telegram->sent);
        $this->send($u, $button, true, 999);
        $this->assertDatabaseCount('game_matchmaking_queue', 1);
        $this->assertDatabaseCount('telegram_updates', 1);
        $this->assertCount($sent, $this->telegram->sent);
    }

    public function test_domain_search_reuses_waiting_row_without_extending_its_expiry(): void
    {
        $u = $this->user('A');
        $first = $this->search($u);
        $row = DB::table('game_matchmaking_queue')->first();
        $this->travel(10)->seconds();
        for ($i = 0; $i < 4; $i++) {
            $this->assertSame($first, $this->search($u));
        }
        $this->assertDatabaseCount('game_matchmaking_queue', 1);
        $this->assertSame($row->expires_at, DB::table('game_matchmaking_queue')->first()->expires_at);
    }

    public function test_cancel_retry_cycles_keep_history_without_unique_violations(): void
    {
        $u = $this->user('A');
        for ($i = 0; $i < 4; $i++) {
            $result = $this->search($u);
            $this->assertTrue(app(GameMatchmakingService::class)->cancel($u, $result['queue_id']));
        }
        $this->search($u);
        $this->assertSame(4, DB::table('game_matchmaking_queue')->where('status', 'cancelled')->count());
        $this->assertSame(1, DB::table('game_matchmaking_queue')->where('status', 'waiting')->count());
    }

    public function test_expire_retry_cycles_preserve_history(): void
    {
        $u = $this->user('A');
        for ($i = 0; $i < 3; $i++) {
            $this->search($u);
            $this->travel(3)->minutes();
        }
        $this->search($u);
        $this->assertSame(3, DB::table('game_matchmaking_queue')->where('status', 'expired')->count());
        $this->assertSame(1, DB::table('game_matchmaking_queue')->where('status', 'waiting')->count());
    }

    public function test_switching_game_types_keeps_only_one_live_entry(): void
    {
        $u = $this->user('A');
        $this->search($u);
        $this->search($u, GameType::TruthOrDare);
        $this->search($u);
        $this->assertSame(1, DB::table('game_matchmaking_queue')->where('status', 'waiting')->count());
        $this->assertSame(2, DB::table('game_matchmaking_queue')->where('status', 'cancelled')->count());
    }

    public function test_matching_and_repeated_requests_create_exactly_one_session_without_charge(): void
    {
        $a = $this->user('A');
        $b = $this->user('B');
        $a->wallet()->create(['balance' => 100]);
        $b->wallet()->create(['balance' => 100]);
        $this->search($a);
        $match = $this->search($b);
        $this->assertTrue($match['matched']);
        foreach ([$a, $b, $a, $b] as $u) {
            $this->assertSame($match['session_id'], $this->search($u, GameType::TruthOrDare)['session_id']);
        }
        $this->assertDatabaseCount('game_sessions', 1);
        $this->assertSame(2, DB::table('game_matchmaking_queue')->where('status', 'matched')->count());
        $this->assertSame(0, DB::table('game_matchmaking_queue')->where('status', 'waiting')->count());
        $this->assertSame(100, $a->wallet->balance);
        $this->assertSame(100, $b->wallet->balance);
        $this->assertDatabaseCount('coin_transactions', 0);
    }

    public function test_completed_match_can_search_again_without_overwriting_history(): void
    {
        $a = $this->user('A');
        $b = $this->user('B');
        $this->search($a);
        $match = $this->search($b);
        GameSession::findOrFail($match['session_id'])->update(['status' => GameStatus::Completed]);
        $this->search($a);
        $again = $this->search($b);
        $this->assertTrue($again['matched']);
        $this->assertNotSame($match['session_id'], $again['session_id']);
        $this->assertSame(4, DB::table('game_matchmaking_queue')->where('status', 'matched')->count());
    }

    public function test_two_users_can_independently_open_games_after_chat_ends_and_cleanup(): void
    {
        $a = $this->user('A');
        $b = $this->user('B');
        $chat = app(ConversationService::class)->connectAnonymous($a->id, $b->id);
        $this->send($a, 's:0:confirm_close_chat_'.$chat->id);
        foreach ([$a, $b] as $u) {
            $revision = InteractionState::where('user_id', $u->id)->value('revision');
            $this->send($u, 'n:0:cleanup_chat_'.$chat->id);
            $this->send($u, __('Games'), false);
            $this->send($u, $this->action($u, 'game_select_rock_paper_scissors'));
            $this->send($u, __('Games'), false);
            $this->send($u, $this->action($u, 'game_select_truth_or_dare'));
            $this->assertStringContainsString(__('Choose one of these games:'), $this->delivered($u));
            $this->assertSame('menu', InteractionState::where('user_id', $u->id)->value('mode'));
        }
        $this->assertDatabaseCount('game_matchmaking_queue', 0);
        $this->assertSame(0, DB::table('telegram_outbox')->whereNull('sent_at')->count());
    }

    public function test_chat_cleanup_then_games_works_through_the_real_http_client(): void
    {
        $a = $this->user('A');
        $b = $this->user('B');
        $chat = app(ConversationService::class)->connectAnonymous($a->id, $b->id);
        app(ConversationService::class)->endConversation($a, $chat);
        config(['telegram.token' => 'test-token']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 500]])]);
        $this->app->instance(TelegramClient::class, new HttpTelegramClient);
        foreach ([$a, $b] as $u) {
            $revision = InteractionState::where('user_id', $u->id)->value('revision');
            $this->send($u, 'n:0:cleanup_chat_'.$chat->id);
            $this->send($u, __('Games'), false);
            $this->send($u, '/start', false);
        }
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/editMessageReplyMarkup'));
        $this->assertSame(0, DB::table('telegram_outbox')->whereNull('sent_at')->count());
        $this->assertSame(0, DB::table('telegram_outbox')->whereNotNull('failed_at')->count());
    }

    public function test_a_game_sql_failure_is_terminal_and_next_start_and_other_user_work(): void
    {
        $a = $this->user('A');
        $b = $this->user('B');
        $a->wallet()->create(['balance' => 75]);
        $fake = \Mockery::mock(GameMatchmakingService::class);
        $fake->shouldReceive('search')->once()->andReturnUsing(function () use ($a) {
            $a->profile()->update(['display_name' => 'Must rollback']);
            $a->wallet()->update(['balance' => 0]);
            DB::statement('SELECT 1 / 0');
        });
        $this->app->instance(GameMatchmakingService::class, $fake);
        $id = $this->send($a, $this->action($a, 'game_random_gender_rock_paper_scissors_male'));
        $row = DB::table('telegram_updates')->where('update_id', $id)->first();
        $this->assertSame('failed', $row->status);
        $this->assertNotNull($row->failed_at);
        $this->assertNotNull($row->processed_at);
        $this->assertSame(75, $a->fresh()->wallet->balance);
        $this->assertSame('A', $a->fresh()->profile->display_name);
        $this->send($a, '/start', false);
        $this->send($b, __('Games'), false);
        $this->assertStringContainsString(__('Welcome to Mito. What would you like to do?'), $this->delivered($a));
        $this->assertStringContainsString(__('Choose one of these games:'), $this->delivered($b));
        $this->assertSame(0, DB::table('telegram_updates')->whereNull('processed_at')->count());
    }

    public function test_poison_payload_has_bounded_attempts_and_does_not_block_later_updates(): void
    {
        $u = $this->user('A');
        DB::table('telegram_updates')->insert(['update_id' => 7, 'telegram_user_id' => $u->telegram_user_id, 'payload' => 'corrupt ciphertext', 'created_at' => now(), 'updated_at' => now()]);
        for ($i = 1; $i <= 3; $i++) {
            try {
                app()->call([new ProcessUpdate(7), 'handle']);
            } catch (DecryptException) {
            }
            $this->assertSame($i, DB::table('telegram_updates')->where('update_id', 7)->value('attempt_count'));
        }
        $this->assertDatabaseHas('telegram_updates', ['update_id' => 7, 'status' => 'failed']);
        $this->send($u, '/start', false);
        app()->call([new ProcessUpdate(7), 'handle']);
        $this->assertSame(3, DB::table('telegram_updates')->where('update_id', 7)->value('attempt_count'));
        $this->assertStringContainsString(__('Welcome to Mito. What would you like to do?'), $this->delivered($u));
    }

    public function test_bad_update_cannot_roll_back_a_previous_update_in_the_same_batch(): void
    {
        $u = $this->user('A');
        $sender = ['id' => $u->telegram_user_id, 'is_bot' => false, 'first_name' => 'A'];
        $payload = ['update_id' => 1, 'message' => ['from' => $sender, 'text' => '/start', 'message_id' => 1, 'chat' => ['id' => $u->telegram_user_id, 'type' => 'private']]];
        DB::table('telegram_updates')->insert([
            ['update_id' => 1, 'telegram_user_id' => $u->telegram_user_id, 'payload' => Crypt::encryptString(json_encode($payload)), 'created_at' => now(), 'updated_at' => now()],
            ['update_id' => 2, 'telegram_user_id' => $u->telegram_user_id, 'payload' => 'bad', 'created_at' => now(), 'updated_at' => now()],
        ]);
        try {
            app()->call([new ProcessUpdate(2), 'handle']);
        } catch (DecryptException) {
        }
        $this->assertDatabaseHas('telegram_updates', ['update_id' => 1, 'status' => 'processed']);
        $this->assertDatabaseHas('telegram_updates', ['update_id' => 2, 'attempt_count' => 1]);
        $this->assertSame(1, DB::table('telegram_outbox')->where('update_id', 1)->count());
    }

    public function test_stale_cancel_cannot_cancel_a_replacement_search(): void
    {
        $u = $this->user('A');
        $first = $this->search($u);
        app(GameMatchmakingService::class)->cancel($u);
        $new = $this->search($u);
        $this->send($u, $this->action($u, 'game_match_cancel_'.$first['queue_id']));
        $this->assertDatabaseHas('game_matchmaking_queue', ['id' => $new['queue_id'], 'status' => 'waiting']);
        $this->assertStringContainsString('این گزینه دیگه فعال نیست.', $this->delivered($u));
    }

    public function test_expired_invitation_callback_is_safe_and_preserves_an_active_chat(): void
    {
        $a = $this->user('A');
        $b = $this->user('B');
        $invite = app(GameService::class)->invite($a, $b, GameType::RockPaperScissors);
        $invite->update(['status' => GameStatus::Expired]);
        $chat = app(ConversationService::class)->connectAnonymous($a->id, $b->id);
        $this->send($b, 'd:0:game_accept_'.$invite->id);
        $this->assertStringContainsString('این گزینه دیگه فعال نیست.', $this->delivered($b));
        $this->assertSame($chat->id, app(ConversationService::class)->activeFor($b)->id);
        $this->assertDatabaseCount('coin_transactions', 0);
    }

    private function outbox(User $user, int $id, string $method): int
    {
        DB::table('telegram_updates')->insert(['update_id' => $id, 'telegram_user_id' => $user->telegram_user_id, 'processed_at' => now(), 'status' => 'processed', 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('telegram_outbox')->insertGetId(['update_id' => $id, 'sequence' => 0, 'payload' => Crypt::encryptString(json_encode(['method' => $method, 'parameters' => ['chat_id' => $user->telegram_user_id, 'text' => 'safe']])), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_orphaned_permanent_outbound_failure_cannot_block_home_forever(): void
    {
        $u = $this->user('A');
        $bad = $this->outbox($u, 1, 'unsupported');
        $home = $this->outbox($u, 2, 'sendMessage');
        $client = new class implements TelegramClient
        {
            public function send(string $method, array $parameters): ?array
            {
                if ($method === 'unsupported') {
                    throw new PermanentTelegramFailure('Unsupported');
                }

                return ['message_id' => 10];
            }
        };
        (new DeliverMessage($home))->handle($client);
        (new DeliverMessage($home))->handle($client);
        $this->assertDatabaseHas('telegram_outbox', ['id' => $bad, 'status' => 'failed', 'attempt_count' => 1, 'sent_at' => null]);
        $this->assertDatabaseHas('telegram_outbox', ['id' => $home, 'status' => 'sent']);
    }

    public function test_transient_delivery_failure_is_bounded_and_next_response_can_send(): void
    {
        $u = $this->user('A');
        $bad = $this->outbox($u, 1, 'sendPhoto');
        $home = $this->outbox($u, 2, 'sendMessage');
        $client = new class implements TelegramClient
        {
            public function send(string $method, array $parameters): ?array
            {
                if ($method === 'sendPhoto') {
                    throw new \RuntimeException('Temporary transport failure');
                }

                return ['message_id' => 10];
            }
        };
        for ($i = 0; $i < 4; $i++) {
            try {
                (new DeliverMessage($home))->handle($client);
            } catch (\RuntimeException) {
            }
        }
        $this->assertDatabaseHas('telegram_outbox', ['id' => $bad, 'status' => 'failed', 'attempt_count' => 3]);
        $this->assertDatabaseHas('telegram_outbox', ['id' => $home, 'status' => 'sent']);
    }

    public function test_cleanup_edit_that_is_already_gone_is_a_successful_noop(): void
    {
        config(['telegram.token' => 'test-token']);
        Http::fake(['*' => Http::response(['ok' => false, 'description' => 'Bad Request: message to edit not found'], 400)]);
        $this->assertNull((new HttpTelegramClient)->send('editMessageReplyMarkup', ['chat_id' => 1, 'message_id' => 2, 'reply_markup' => ['inline_keyboard' => []]]));
    }

    public function test_old_constraint_reproduction_conflicts_on_cancellation_not_games_root(): void
    {
        DB::statement('CREATE TEMP TABLE old_game_queue (user_id bigint, status varchar(20), CONSTRAINT old_game_queue_user_status UNIQUE (user_id,status)) ON COMMIT DROP');
        DB::insert("INSERT INTO old_game_queue VALUES (1, 'cancelled'), (1, 'waiting')");
        try {
            DB::transaction(fn () => DB::update("UPDATE old_game_queue SET status='cancelled' WHERE user_id=1 AND status='waiting'"));
            $this->fail('Old schema must reproduce PostgreSQL 23505');
        } catch (QueryException $e) {
            $this->assertSame('23505', (string) $e->getCode());
            $this->assertStringContainsString('old_game_queue_user_status', $e->getMessage());
        }
    }

    public function test_ending_chat_preserves_unrelated_valid_game_state(): void
    {
        $a = $this->user('A');
        $b = $this->user('B');
        $chat = app(ConversationService::class)->connectAnonymous($a->id, $b->id);
        $game = app(GameService::class)->invite($a, $b, GameType::TruthOrDare);
        $state = InteractionState::updateOrCreate(['user_id' => $b->id], ['mode' => 'game_truth_or_dare', 'conversation_id' => $chat->id, 'game_context' => ['session_id' => $game->id]]);
        app(ConversationService::class)->endConversation($a, $chat);
        $this->assertSame('game_truth_or_dare', $state->fresh()->mode);
        $this->assertSame(['session_id' => $game->id], $state->fresh()->game_context);
        $this->assertNull($state->fresh()->conversation_id);
        $this->assertSame(GameStatus::Waiting, $game->fresh()->status);
    }

    public function test_pending_invitation_prevents_another_random_entry_and_preserves_wallet(): void
    {
        $a = $this->user('A');
        $b = $this->user('B');
        $a->wallet()->create(['balance' => 100]);
        $this->search($a);
        $invite = app(GameService::class)->invite($a, $b, GameType::RockPaperScissors);
        $this->assertSame($invite->id, $this->search($a)['session_id']);
        $this->assertSame(0, DB::table('game_matchmaking_queue')->where('status', 'waiting')->count());
        app(GameService::class)->accept($b, $invite);
        app(GameService::class)->accept($b, $invite);
        $this->assertSame(98, $a->fresh()->wallet->balance);
        $this->assertSame(1, DB::table('coin_transactions')->where('idempotency_key', 'game_invitation:'.$invite->id)->count());
        $this->assertDatabaseCount('game_sessions', 1);
    }

    public function test_truth_or_dare_random_match_starts_exactly_one_round(): void
    {
        $a = $this->user('A');
        $b = $this->user('B');
        $this->send($a, $this->action($a, 'game_random_gender_truth_or_dare_male'));
        $this->send($b, $this->action($b, 'game_random_gender_truth_or_dare_male'));
        $this->assertDatabaseCount('game_sessions', 1);
        $this->assertDatabaseHas('game_sessions', ['status' => 'active', 'current_round' => 1, 'game_type' => 'truth_or_dare']);
        $this->assertDatabaseCount('game_rounds', 1);
        $this->assertSame(0, DB::table('telegram_updates')->where('status', 'failed')->count());
    }

    public function test_terminal_update_stays_failed_even_if_state_recovery_throws(): void
    {
        $u = $this->user('A');
        DB::table('telegram_updates')->insert(['update_id' => 7, 'telegram_user_id' => $u->telegram_user_id, 'payload' => 'corrupt', 'attempt_count' => 2, 'created_at' => now(), 'updated_at' => now()]);
        InteractionState::saving(fn () => throw new \RuntimeException('Broken state storage'));
        app()->call([new ProcessUpdate(7), 'handle']);
        $this->assertDatabaseHas('telegram_updates', ['update_id' => 7, 'status' => 'failed', 'attempt_count' => 3]);
        $this->assertNotNull(DB::table('telegram_updates')->where('update_id', 7)->value('processed_at'));
    }
}
