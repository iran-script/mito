<?php

namespace Tests\Feature;

use App\Domain\Games\GameService;
use App\Domain\Games\GameSession;
use App\Domain\Games\GameStatus;
use App\Domain\Games\GameType;
use App\Domain\Games\TwoTruthsService;
use App\Domain\Moderation\ContactInformationGuard;
use App\Domain\Profiles\City;
use App\Domain\Profiles\Gender;
use App\Domain\Profiles\Profile;
use App\Domain\Profiles\ProfileStatus;
use App\Domain\Profiles\RegistrationState;
use App\Domain\Telegram\InteractionState;
use App\Domain\Telegram\Jobs\DeliverSocialNotification;
use App\Domain\Telegram\TelegramClient;
use App\Domain\Users\BlockService;
use App\Domain\Users\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\FakeTelegramClient;
use Tests\TestCase;

class TwoTruthsTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 5000;

    private TwoTruthsService $service;

    private FakeTelegramClient $telegram;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->service = app(TwoTruthsService::class);
        $this->telegram = new FakeTelegramClient;
        $this->app->instance(TelegramClient::class, $this->telegram);
        config(['telegram.webhook_secret' => 'test-secret']);
    }

    private function user(string $name): User
    {
        $user = User::create(['telegram_user_id' => random_int(100000000, 999999999), 'telegram_username' => 'private_'.$name, 'last_activity_at' => now()]);
        Profile::create(['user_id' => $user->id, 'display_name' => $name, 'birth_date' => '2000-01-01', 'gender' => Gender::Male, 'city_id' => City::first()->id, 'status' => ProfileStatus::Active, 'profile_completed_at' => now()]);
        RegistrationState::create(['user_id' => $user->id, 'step' => 'complete']);

        return $user;
    }

    private function game(bool $accept = true): array
    {
        $a = $this->user('A');
        $b = $this->user('B');
        $session = app(GameService::class)->invite($a, $b, GameType::TwoTruthsOneLie);
        if ($accept) {
            app(GameService::class)->accept($b, $session);
        }

        return [$a, $b, $session->fresh()];
    }

    private function statements(User $user, GameSession $s): void
    {
        foreach (['I play piano', 'I climbed a mountain', 'I speak six languages'] as $text) {
            $this->service->addStatement($user, $s, $text);
        }
    }

    private function ready(User $user, GameSession $s, int $lie = 1): int
    {
        $this->statements($user, $s);
        $this->service->selectLie($user, $s->fresh(), $lie);

        return array_search($lie, $s->fresh()->state['tt_presentation_order'], true);
    }

    private function rejects(callable $action): void
    {
        try {
            $action();
            $this->fail('Expected the action to be rejected.');
        } catch (\DomainException $e) {
            $this->assertNotEmpty($e->getMessage());
        }
    }

    public function test_start_reuses_existing_schema_and_is_idempotent(): void
    {
        [$a, $b, $s] = $this->game();
        $this->service->addStatement($a, $s, 'I play piano');
        $before = $s->fresh()->state;
        $this->service->start($s);
        app(GameService::class)->accept($b, $s);
        $this->assertSame($before, $s->fresh()->state);
        $this->assertDatabaseCount('game_rounds', 0);
        $this->assertSame($a->id, $before['tt_creator_id']);
        $this->assertSame($b->id, $before['tt_guesser_id']);
    }

    public function test_three_distinct_nonempty_bounded_statements_are_required(): void
    {
        [$a, $b, $s] = $this->game();
        $this->rejects(fn () => $this->service->addStatement($a, $s, '   '));
        $this->rejects(fn () => $this->service->addStatement($a, $s, str_repeat('x', 241)));
        $this->service->addStatement($a, $s, 'I play piano');
        $this->rejects(fn () => $this->service->addStatement($a, $s, ' i  PLAY piano '));
        $this->rejects(fn () => $this->service->selectLie($a, $s, 0));
        $this->service->addStatement($a, $s, 'I paint');
        $this->assertTrue($this->service->addStatement($a, $s, 'I swim')['complete']);
        $this->rejects(fn () => $this->service->addStatement($a, $s, 'A fourth statement'));
        $this->assertCount(3, $s->fresh()->state['tt_statements']);
    }

    public function test_contact_guard_and_numeric_telegram_identity_are_enforced(): void
    {
        [$a, $b, $s] = $this->game();
        foreach (['Contact @someone', 't.me/someone', 'tg://user?id=123', (string) $a->telegram_user_id, 'private_A'] as $text) {
            $this->rejects(fn () => $this->service->addStatement($a, $s, $text));
        }
        $this->mock(ContactInformationGuard::class, fn ($mock) => $mock->shouldReceive('blocked')->once()->with('custom forbidden statement')->andReturn(true));
        $this->rejects(fn () => app(TwoTruthsService::class)->addStatement($a, $s, 'custom forbidden statement'));
        $this->assertSame([], $s->fresh()->state['tt_statements']);
    }

    public function test_wrong_players_and_outsiders_cannot_read_or_mutate_the_round(): void
    {
        [$a, $b, $s] = $this->game();
        $outsider = $this->user('C');
        $this->rejects(fn () => $this->service->view($outsider, $s));
        $this->rejects(fn () => $this->service->addStatement($b, $s, 'Not my turn'));
        $this->statements($a, $s);
        $this->assertSame(['phase' => 'waiting'], $this->service->view($b, $s));
        $this->rejects(fn () => $this->service->selectLie($b, $s, 0));
        $this->service->selectLie($a, $s, 1);
        $this->rejects(fn () => $this->service->guess($a, $s, 0));
        $this->rejects(fn () => $this->service->guess($outsider, $s, 0));
        $this->assertDatabaseCount('game_answers', 0);
    }

    public function test_lie_selection_is_single_immutable_and_presentation_is_private(): void
    {
        [$a, $b, $s] = $this->game();
        $this->statements($a, $s);
        foreach ([-1, 3] as $index) {
            $this->rejects(fn () => $this->service->selectLie($a, $s, $index));
        }
        $this->service->selectLie($a, $s, 1);
        $before = $s->fresh()->state;
        $this->assertTrue($this->service->selectLie($a, $s, 2)['duplicate']);
        $this->assertSame($before, $s->fresh()->state);
        $order = $before['tt_presentation_order'];
        $this->assertEqualsCanonicalizing([0, 1, 2], $order);
        $view = $this->service->view($b, $s);
        $this->assertSame(array_map(fn ($i) => $before['tt_statements'][$i], $order), $view['statements']);
        $this->assertSame(['phase', 'round', 'statements'], array_keys($view));
        $this->assertSame($this->service->guessQuestion($s), app(GameService::class)->question($s));
        $this->assertDatabaseCount('game_rounds', 1);
    }

    public function test_server_resolves_shuffled_guess_and_reverses_roles(): void
    {
        [$a, $b, $s] = $this->game();
        $index = $this->ready($a, $s);
        $this->rejects(fn () => $this->service->guess($b, $s, 3));
        $result = $this->service->guess($b, $s, $index, 1);
        $this->assertTrue($result['correct']);
        $this->assertFalse($result['completed']);
        $this->assertSame('I climbed a mountain', $result['lie']);
        $current = $s->fresh();
        $this->assertSame(2, $current->current_round);
        $this->assertSame($b->id, $current->state['tt_creator_id']);
        $this->assertSame($a->id, $current->state['tt_guesser_id']);
        $this->assertSame([], $current->state['tt_statements']);
        $this->assertSame(1, $current->state['tt_correct_guesses'][$b->id]);
        $this->assertDatabaseCount('game_player_stats', 0);
    }

    public function test_both_directions_complete_with_xp_once_and_no_rating_or_coins(): void
    {
        [$a, $b, $s] = $this->game();
        $index = $this->ready($a, $s);
        $this->service->guess($b, $s, $index, 1);
        $index = $this->ready($b, $s->fresh(), 2);
        $result = $this->service->guess($a, $s->fresh(), ($index + 1) % 3, 2);
        $this->assertFalse($result['correct']);
        $this->assertTrue($result['completed']);
        $this->assertSame(GameStatus::Completed, $s->fresh()->status);
        $this->assertCount(2, $s->fresh()->state['tt_result']['reveals']);
        $this->assertTrue($this->service->guess($a, $s->fresh(), $index, 2)['duplicate']);
        app(GameService::class)->settleSocial($s, 999);
        foreach ([[$a, 15, 0], [$b, 20, 1]] as [$user, $xp, $correct]) {
            $stats = app(GameService::class)->stats($user);
            $this->assertSame($xp, $stats->xp);
            $this->assertSame(1, $stats->games_played);
            $this->assertSame(1000, $stats->competitive_score);
            $this->assertSame(0, $stats->wins + $stats->losses + $stats->draws);
            $this->assertDatabaseHas('game_participants', ['game_session_id' => $s->id, 'user_id' => $user->id, 'score' => $correct, 'xp_earned' => $xp]);
        }
        $this->assertDatabaseCount('game_rating_events', 0);
        $this->assertDatabaseCount('coin_transactions', 0);
        $this->assertDatabaseCount('game_answers', 2);
        $this->assertDatabaseCount('game_rounds', 2);
    }

    public function test_old_round_callbacks_do_not_change_reversed_round(): void
    {
        [$a, $b, $s] = $this->game();
        $index = $this->ready($a, $s);
        $this->service->guess($b, $s, $index, 1);
        $before = $s->fresh()->state;
        $notifications = DB::table('social_outbox')->count();
        $this->assertTrue($this->service->guess($b, $s->fresh(), 0, 1)['duplicate']);
        $this->assertTrue($this->service->selectLie($a, $s->fresh(), 2, 1)['duplicate']);
        $this->rejects(fn () => $this->service->selectLie($b, $s->fresh(), 0, 1));
        $this->assertSame($before, $s->fresh()->state);
        $this->assertSame($notifications, DB::table('social_outbox')->count());
    }

    public function test_generic_answer_and_round_creation_cannot_bypass_game_rules(): void
    {
        [$a, $b, $s] = $this->game();
        $this->rejects(fn () => app(GameService::class)->answer($a, $s, 'fake'));
        $this->rejects(fn () => app(GameService::class)->ensureRound($s));
        $this->assertDatabaseCount('game_answers', 0);
        $this->assertDatabaseCount('game_rounds', 0);
    }

    public function test_block_cancels_and_prevents_queued_delivery_and_rewards(): void
    {
        [$a, $b, $s] = $this->game();
        $index = $this->ready($a, $s);
        app(BlockService::class)->block($b, $a);
        $this->assertSame(GameStatus::Cancelled, $s->fresh()->status);
        $this->rejects(fn () => $this->service->guess($b, $s, $index));
        $client = $this->mock(TelegramClient::class);
        $client->shouldNotReceive('send');
        foreach (DB::table('social_outbox')->pluck('id') as $id) {
            (new DeliverSocialNotification($id))->handle($client);
        }
        $this->assertDatabaseCount('game_answers', 0);
        $this->assertDatabaseCount('game_player_stats', 0);
        $this->assertSame(0, DB::table('social_outbox')->where('payload', '!=', '')->count());
    }

    public function test_expiry_cancels_on_action_without_waiting_for_scheduler(): void
    {
        [$a, $b, $s] = $this->game();
        $index = $this->ready($a, $s);
        $s->update(['expires_at' => now()->subSecond()]);
        $this->rejects(fn () => $this->service->guess($b, $s, $index));
        $this->assertSame(GameStatus::Expired, $s->fresh()->status);
        $this->assertDatabaseCount('game_answers', 0);
        $this->assertDatabaseCount('game_player_stats', 0);
    }

    public function test_expired_statement_context_is_cleared(): void
    {
        [$a, $b, $s] = $this->game();
        $this->service->view($a, $s);
        $s->update(['expires_at' => now()->subSecond()]);
        $this->rejects(fn () => $this->service->addStatement($a, $s, 'I paint'));
        $this->assertDatabaseHas('interaction_states', ['user_id' => $a->id, 'mode' => 'menu', 'game_context' => null]);
        $this->assertSame(GameStatus::Expired, $s->fresh()->status);
    }

    public function test_inviter_cannot_accept_and_expired_invitation_cannot_start(): void
    {
        [$a, $b, $s] = $this->game(false);
        $this->rejects(fn () => app(GameService::class)->accept($a, $s));
        $this->rejects(fn () => $this->service->start($s));
        $s->update(['expires_at' => now()->subSecond()]);
        app(GameService::class)->accept($b, $s);
        $this->assertSame(GameStatus::Expired, $s->fresh()->status);
        $this->assertArrayNotHasKey('tt_phase', $s->fresh()->state);
    }

    private function send(User $user, string $value, bool $callback = true): void
    {
        $id = ++$this->sequence;
        $sender = ['id' => $user->telegram_user_id, 'is_bot' => false, 'first_name' => 'Private', 'username' => $user->telegram_username];
        $message = ['message_id' => $id, 'chat' => ['id' => $user->telegram_user_id, 'type' => 'private']];
        $payload = ['update_id' => $id] + ($callback ? ['callback_query' => ['id' => 'cb'.$id, 'from' => $sender, 'message' => $message, 'data' => $value]] : ['message' => $message + ['from' => $sender, 'text' => $value]]);
        $this->postJson('/api/telegram/webhook', $payload, ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret'])->assertOk();
        foreach (['telegram', 'telegram-outbound'] as $queue) {
            while ($job = Queue::connection('database')->pop($queue)) {
                $job->fire();
                $job->delete();
            }
        }
    }

    public function test_real_webhook_gameplay_delivers_both_directions_and_hides_identity(): void
    {
        [$a, $b, $s] = $this->game(false);
        foreach ([$a, $b] as $user) {
            InteractionState::create(['user_id' => $user->id, 'mode' => 'chat']);
        }
        $this->send($b, 'd:0:game_tt_accept_'.$s->id);
        $this->send($a, 'd:0:game_tt_open_'.$s->id);
        foreach (['I play piano', 'I climbed a mountain', 'I speak six languages'] as $text) {
            $this->send($a, $text, false);
        }
        $this->assertCount(3, $s->fresh()->state['tt_statements']);
        $this->send($a, 'd:0:game_tt_lie_'.$s->id.'_1_1');
        $this->send($b, 'd:0:game_tt_open_'.$s->id);
        $beforeGuess = json_encode($this->telegram->sent);
        $this->assertStringNotContainsString('The lie was:', $beforeGuess);
        $this->assertStringNotContainsString('lie_index', $beforeGuess);
        $this->assertStringNotContainsString('presentation_order', $beforeGuess);
        $index = array_search(1, $s->fresh()->state['tt_presentation_order'], true);
        $this->send($b, 'd:0:game_tt_guess_'.$s->id.'_1_'.$index);
        $this->assertSame('game_two_truths', InteractionState::where('user_id', $b->id)->value('mode'));
        foreach (['I paint portraits', 'I can juggle', 'I own a horse'] as $text) {
            $this->send($b, $text, false);
        }
        $this->send($b, 'd:0:game_tt_lie_'.$s->id.'_2_2');
        $this->send($a, 'd:0:game_tt_open_'.$s->id);
        $index = array_search(2, $s->fresh()->state['tt_presentation_order'], true);
        $this->send($a, 'd:0:game_tt_guess_'.$s->id.'_2_'.$index);
        $this->send($a, 'd:0:game_tt_guess_'.$s->id.'_2_'.$index);
        $this->assertSame(GameStatus::Completed, $s->fresh()->status);
        $this->assertSame(20, app(GameService::class)->stats($a)->xp);
        $this->assertSame(20, app(GameService::class)->stats($b)->xp);
        $this->assertDatabaseCount('game_answers', 2);
        $this->assertSame(0, InteractionState::where('mode', 'game_two_truths')->count());
        $this->assertSame(0, DB::table('telegram_updates')->whereNull('processed_at')->count());
        $this->assertSame(0, DB::table('social_outbox')->whereNull('sent_at')->count());
        foreach ($this->telegram->sent as $message) {
            $parameters = $message['parameters'];
            // The destination chat ID is transport metadata, never visible message content.
            unset($parameters['chat_id']);
            $visible = json_encode($parameters);
            foreach ([$a, $b] as $user) {
                $this->assertStringNotContainsString((string) $user->telegram_user_id, $visible);
                $this->assertStringNotContainsString($user->telegram_username, $visible);
            }
            foreach ($parameters['reply_markup']['inline_keyboard'] ?? [] as $row) {
                foreach ($row as $button) {
                    $this->assertLessThanOrEqual(64, strlen($button['callback_data']));
                }
            }
        }
        foreach ([$a, $b] as $user) {
            $delivered = collect($this->telegram->sent)->filter(fn ($m) => ($m['parameters']['chat_id'] ?? null) === $user->telegram_user_id)->pluck('parameters.text')->implode(' ');
            $this->assertStringContainsString('The lie was:', $delivered);
            $this->assertStringContainsString('Two Truths and a Lie complete', $delivered);
        }
    }
}
