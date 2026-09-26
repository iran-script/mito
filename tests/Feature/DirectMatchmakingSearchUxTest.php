<?php

namespace Tests\Feature;

use App\Domain\Direct\DirectMessageService;
use App\Domain\Discovery\AnonymousMatchmakingService;
use App\Domain\Payments\CoinTransactionType;
use App\Domain\Payments\WalletService;
use App\Domain\Profiles\City;
use App\Domain\Profiles\Gender;
use App\Domain\Profiles\Profile;
use App\Domain\Profiles\ProfileStatus;
use App\Domain\Profiles\RegistrationState;
use App\Domain\Telegram\DiscoveryInteraction;
use App\Domain\Telegram\IncomingUpdate;
use App\Domain\Telegram\InteractionState;
use App\Domain\Telegram\Jobs\MatchmakingPoll;
use App\Domain\Telegram\Keyboard;
use App\Domain\Telegram\SocialInteraction;
use App\Domain\Telegram\SocialNotificationService;
use App\Domain\Users\BlockService;
use App\Domain\Users\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DirectMatchmakingSearchUxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        app()->setLocale('fa');
    }

    private function user(string $name, Gender $gender): User
    {
        $u = User::create(['telegram_user_id' => random_int(1000000, 9999999), 'last_activity_at' => now()]);
        Profile::create(['user_id' => $u->id, 'display_name' => $name, 'birth_date' => '2000-01-01', 'gender' => $gender, 'city_id' => City::firstOrFail()->id, 'status' => ProfileStatus::Active, 'profile_completed_at' => now()]);

        return $u->fresh('profile');
    }

    public function test_search_root_has_modes_without_gender_buttons(): void
    {
        $flat = array_merge(...Keyboard::searchReply());
        $this->assertNotContains(__('Search women'), $flat);
        $this->assertNotContains(__('Search men'), $flat);
        $this->assertContains(__('Anonymous search'), $flat);
        $this->assertContains(__('Same city'), $flat);
    }

    public function test_reciprocal_waiters_match_once_without_creating_paid_chat(): void
    {
        $a = $this->user('A', Gender::Female);
        $b = $this->user('B', Gender::Male);
        $now = now();
        DB::table('matchmaking_searches')->insert([
            ['user_id' => $a->id, 'gender' => 'male', 'status' => 'waiting', 'generation' => 1, 'started_at' => $now, 'expires_at' => $now->copy()->addSeconds(120), 'created_at' => $now, 'updated_at' => $now],
            ['user_id' => $b->id, 'gender' => 'female', 'status' => 'waiting', 'generation' => 1, 'started_at' => $now, 'expires_at' => $now->copy()->addSeconds(120), 'created_at' => $now, 'updated_at' => $now],
        ]);
        $pair = app(AnonymousMatchmakingService::class)->attempt($a->id, 1);
        $this->assertNotNull($pair);
        $this->assertNull(app(AnonymousMatchmakingService::class)->attempt($a->id, 1));
        $this->assertSame('matched', DB::table('matchmaking_searches')->where('user_id', $a->id)->value('status'));
        $this->assertSame($b->id, DB::table('matchmaking_searches')->where('user_id', $a->id)->value('matched_user_id'));
        $this->assertDatabaseCount('conversations', 1);
        $this->assertDatabaseCount('conversation_participants', 2);
        $this->assertDatabaseCount('chat_requests', 0);
        $this->assertDatabaseCount('coin_transactions', 0);
    }

    public function test_blocked_or_cancelled_waiter_never_matches(): void
    {
        $a = $this->user('A', Gender::Female);
        $b = $this->user('B', Gender::Male);
        app(BlockService::class)->block($a, $b);
        $now = now();
        foreach ([[$a, 'male', 'waiting'], [$b, 'female', 'waiting']] as [$u,$g,$status]) {
            DB::table('matchmaking_searches')->insert(['user_id' => $u->id, 'gender' => $g, 'status' => $status, 'generation' => 1, 'started_at' => $now, 'expires_at' => $now->copy()->addSeconds(120), 'created_at' => $now, 'updated_at' => $now]);
        }
        $this->assertNull(app(AnonymousMatchmakingService::class)->attempt($a->id, 1));
    }

    public function test_direct_notification_does_not_mark_seen_and_first_open_is_unique(): void
    {
        $a = $this->user('A', Gender::Female);
        $b = $this->user('B', Gender::Male);
        app(WalletService::class)->wallet($a)->update(['balance' => 100]);
        $m = app(DirectMessageService::class)->send($a, $b, 'hello', 'direct-review-test');
        app(SocialNotificationService::class)->directMessage($b, $m);
        $this->assertNull($m->fresh()->seen_at);
        [, $first] = app(DirectMessageService::class)->markSeenWithStatus($b, $m);
        [, $again] = app(DirectMessageService::class)->markSeenWithStatus($b, $m);
        $this->assertTrue($first);
        $this->assertFalse($again);
        $this->assertNotNull($m->fresh()->seen_at);
    }

    public function test_matchmaking_poll_auto_connects_both_users_for_free_with_chat_keyboard(): void
    {
        $a = $this->user('سارا', Gender::Female);
        $b = $this->user('علی', Gender::Male);
        InteractionState::create(['user_id' => $a->id]);
        InteractionState::create(['user_id' => $b->id]);
        RegistrationState::create(['user_id' => $a->id, 'step' => 'complete']);
        RegistrationState::create(['user_id' => $b->id, 'step' => 'complete']);
        $now = now();
        foreach ([[$a, 'male'], [$b, 'female']] as [$user, $gender]) {
            DB::table('matchmaking_searches')->insert(['user_id' => $user->id, 'gender' => $gender, 'status' => 'waiting', 'generation' => 1, 'started_at' => $now, 'expires_at' => $now->copy()->addSeconds(120), 'created_at' => $now, 'updated_at' => $now]);
        }

        app()->call([new MatchmakingPoll($a->id, 1), 'handle']);

        $conversationId = DB::table('conversations')->value('id');
        $this->assertNotNull($conversationId);
        $this->assertDatabaseCount('conversations', 1);
        $this->assertDatabaseCount('conversation_participants', 2);
        $this->assertDatabaseCount('chat_requests', 0);
        $this->assertDatabaseCount('coin_transactions', 0);
        foreach ([$a, $b] as $user) {
            $state = InteractionState::where('user_id', $user->id)->firstOrFail();
            $this->assertSame('chat', $state->mode);
            $this->assertSame($conversationId, $state->conversation_id);
        }
        $payloads = DB::table('social_outbox')->orderBy('id')->pluck('payload')->map(fn (string $payload) => json_decode(Crypt::decryptString($payload), true, 512, JSON_THROW_ON_ERROR));
        $this->assertCount(2, $payloads);
        foreach ($payloads as $payload) {
            $this->assertStringContainsString('گفتگو وصل شد', $payload['parameters']['text']);
            $this->assertArrayHasKey('keyboard', $payload['parameters']['reply_markup']);
            $this->assertStringNotContainsString('profile_', json_encode($payload, JSON_UNESCAPED_UNICODE));
            $this->assertStringNotContainsString('request_', json_encode($payload, JSON_UNESCAPED_UNICODE));
        }
    }

    public function test_search_results_use_direct_persian_user_rows_without_number_buttons(): void
    {
        $viewer = $this->user('بیننده', Gender::Female);
        $targets = collect(range(1, 6))->map(fn (int $i) => $this->user('کاربر '.$i, Gender::Male));
        $state = RegistrationState::create(['user_id' => $viewer->id, 'step' => 'complete']);
        InteractionState::create(['user_id' => $viewer->id, 'mode' => 'search']);
        $update = new IncomingUpdate(['update_id' => 101, 'callback_query' => [
            'id' => 'search', 'data' => 'd:0:city_male',
            'from' => ['id' => $viewer->telegram_user_id, 'is_bot' => false, 'first_name' => 'Test'],
            'message' => ['message_id' => 1, 'chat' => ['id' => $viewer->telegram_user_id, 'type' => 'private']],
        ]]);

        $messages = app(DiscoveryInteraction::class)->handle($viewer, $update, $state);
        $parameters = $messages[0]['parameters'];
        $buttons = collect($parameters['reply_markup']['inline_keyboard'])->flatten(1);
        $profileButtons = $buttons->filter(fn (array $button) => str_contains($button['callback_data'], 'profile_'))->values();
        $this->assertCount(5, $profileButtons);
        $this->assertTrue($profileButtons->every(fn (array $button) => str_contains($button['text'], '👤') && str_contains($button['text'], '/m_')));
        $this->assertFalse($profileButtons->contains(fn (array $button) => preg_match('/^[1-5]$/', $button['text']) === 1));
        $this->assertStringContainsString('ساله', $parameters['text']);
        $this->assertStringContainsString('/m_', $parameters['text']);
        $this->assertTrue($buttons->contains(fn (array $button) => $button['text'] === __('Next')));
        $state->increment('revision');
        $state->refresh();

        $profile = app(DiscoveryInteraction::class)->handle($viewer, new IncomingUpdate(['update_id' => 102, 'callback_query' => [
            'id' => 'profile', 'data' => $profileButtons[0]['callback_data'],
            'from' => ['id' => $viewer->telegram_user_id, 'is_bot' => false, 'first_name' => 'Test'],
            'message' => ['message_id' => 2, 'chat' => ['id' => $viewer->telegram_user_id, 'type' => 'private']],
        ]]), $state);
        $selectedId = (int) explode('_', $profileButtons[0]['callback_data'])[1];
        $this->assertStringContainsString($targets->firstWhere('id', $selectedId)->profile->display_name, json_encode($profile, JSON_UNESCAPED_UNICODE));
    }

    public function test_test_credit_is_flagged_ledger_backed_and_idempotent_per_update(): void
    {
        $user = $this->user('کیف پول', Gender::Female);
        $state = InteractionState::create(['user_id' => $user->id]);
        config(['economy.wallet_test_credit_enabled' => true, 'economy.wallet_test_credit_amount' => 100]);
        $interaction = app(SocialInteraction::class);
        $update = fn (int $id) => new IncomingUpdate(['update_id' => $id, 'callback_query' => [
            'id' => 'wallet-'.$id, 'data' => 's:0:test_credit',
            'from' => ['id' => $user->telegram_user_id, 'is_bot' => false, 'first_name' => 'Test'],
            'message' => ['message_id' => $id, 'chat' => ['id' => $user->telegram_user_id, 'type' => 'private']],
        ]]);

        $first = $interaction->handle($user, $update(201), $state);
        $interaction->handle($user, $update(201), $state);
        $this->assertSame(100, app(WalletService::class)->wallet($user)->fresh()->balance);
        $this->assertDatabaseCount('coin_transactions', 1);
        $this->assertDatabaseHas('coin_transactions', ['user_id' => $user->id, 'type' => CoinTransactionType::TestCredit->value, 'amount' => 100, 'code' => 'test_credit']);
        $this->assertStringContainsString('۱۰۰ سکه آزمایشی', json_encode($first, JSON_UNESCAPED_UNICODE));

        $interaction->handle($user, $update(202), $state);
        $this->assertSame(200, app(WalletService::class)->wallet($user)->fresh()->balance);
        $this->assertDatabaseCount('coin_transactions', 2);
        $this->assertDatabaseCount('coin_purchase_orders', 0);

        $wallet = $interaction->handle($user, new IncomingUpdate(['update_id' => 203, 'callback_query' => [
            'id' => 'wallet', 'data' => 's:0:wallet',
            'from' => ['id' => $user->telegram_user_id, 'is_bot' => false, 'first_name' => 'Test'],
            'message' => ['message_id' => 203, 'chat' => ['id' => $user->telegram_user_id, 'type' => 'private']],
        ]]), $state);
        $this->assertStringContainsString('افزودن ۱۰۰ سکه آزمایشی', json_encode($wallet, JSON_UNESCAPED_UNICODE));
        config(['economy.wallet_test_credit_enabled' => false]);
        $hidden = $interaction->handle($user, new IncomingUpdate(['update_id' => 204, 'callback_query' => [
            'id' => 'wallet-hidden', 'data' => 's:0:wallet',
            'from' => ['id' => $user->telegram_user_id, 'is_bot' => false, 'first_name' => 'Test'],
            'message' => ['message_id' => 204, 'chat' => ['id' => $user->telegram_user_id, 'type' => 'private']],
        ]]), $state);
        $this->assertStringNotContainsString('افزودن ۱۰۰ سکه آزمایشی', json_encode($hidden, JSON_UNESCAPED_UNICODE));
    }
}
