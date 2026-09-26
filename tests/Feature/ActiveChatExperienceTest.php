<?php

namespace Tests\Feature;

use App\Domain\Chat\ChatRequest;
use App\Domain\Chat\ChatRequestService;
use App\Domain\Chat\Conversation;
use App\Domain\Chat\ConversationService;
use App\Domain\Chat\ConversationStatus;
use App\Domain\Payments\WalletService;
use App\Domain\Telegram\InteractionState;
use App\Domain\Telegram\Keyboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InteractsWithGuessGames;
use Tests\TestCase;

class ActiveChatExperienceTest extends TestCase
{
    use InteractsWithGuessGames, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootGameTests();
        app()->setLocale('fa');
    }

    private function connect(): array
    {
        $a = $this->user('A');
        $b = $this->user('B');
        app(WalletService::class)->wallet($a)->update(['balance' => 100]);
        app(WalletService::class)->wallet($b)->update(['balance' => 100]);
        $this->send($a, 's:0:request_'.$b->id);
        $request = ChatRequest::sole();
        $this->send($b, 'n:0:view_request_'.$request->id);
        $revision = InteractionState::where('user_id', $b->id)->value('revision');
        $this->telegram->sent = [];
        $this->send($b, 's:'.$revision.':accept_request_'.$request->id);

        return [$a, $b, $request->fresh(), Conversation::sole()];
    }

    private function sendMedia($user, string $kind, string $fileId): void
    {
        $id = random_int(1000000, 9999999);
        $sender = ['id' => $user->telegram_user_id, 'is_bot' => false, 'first_name' => 'Private'];
        $message = [
            'message_id' => $id,
            'chat' => ['id' => $user->telegram_user_id, 'type' => 'private'],
            'from' => $sender,
        ];
        if ($kind === 'photo') {
            $message['photo'] = [['file_id' => $fileId]];
        } else {
            $message['voice'] = ['file_id' => $fileId, 'duration' => 2];
        }
        $this->postJson('/api/telegram/webhook', ['update_id' => $id, 'message' => $message], [
            'X-Telegram-Bot-Api-Secret-Token' => 'test-secret',
        ])->assertOk();
        foreach (['telegram', 'telegram-outbound'] as $queue) {
            while ($job = Queue::connection('database')->pop($queue)) {
                $job->fire();
                $job->delete();
            }
        }
    }

    public function test_closed_pair_is_reactivated_with_both_participants_and_relays_both_directions(): void
    {
        $a = $this->user('A');
        $b = $this->user('B');
        app(WalletService::class)->wallet($a)->update(['balance' => 100]);
        $service = app(ChatRequestService::class);
        $conversation = $service->accept($b, $service->create($a, $b));
        app(ConversationService::class)->close($a, $conversation);
        $second = $service->create($a, $b);
        $reactivated = $service->accept($b, $second);

        $this->assertSame($conversation->id, $reactivated->id);
        $this->assertSame(ConversationStatus::Active, $reactivated->status);
        $this->assertSame(2, $reactivated->participants()->count());
        $this->assertTrue(app(ConversationService::class)->activeFor($a)->is($reactivated));
        $this->assertTrue(app(ConversationService::class)->activeFor($b)->is($reactivated));

        InteractionState::updateOrCreate(['user_id' => $a->id], ['mode' => 'chat', 'conversation_id' => $reactivated->id]);
        InteractionState::updateOrCreate(['user_id' => $b->id], ['mode' => 'chat', 'conversation_id' => $reactivated->id]);
        $this->telegram->sent = [];
        $this->send($a, 'ط·آ·ط¢آ·ط·آ¢ط¢آ³ط·آ·ط¢آ¸ط£آ¢أ¢â€ڑآ¬أ¢â‚¬ع†ط·آ·ط¢آ·ط·آ¢ط¢آ§ط·آ·ط¢آ¸ط£آ¢أ¢â€ڑآ¬ط¢آ¦', false);
        $this->assertStringContainsString('ط·آ·ط¢آ·ط·آ¢ط¢آ³ط·آ·ط¢آ¸ط£آ¢أ¢â€ڑآ¬أ¢â‚¬ع†ط·آ·ط¢آ·ط·آ¢ط¢آ§ط·آ·ط¢آ¸ط£آ¢أ¢â€ڑآ¬ط¢آ¦', $this->delivered($b));
        $this->send($b, 'ط·آ·ط¢آ·ط·آ¢ط¢آ³ط·آ·ط¢آ¸ط£آ¢أ¢â€ڑآ¬أ¢â‚¬ع†ط·آ·ط¢آ·ط·آ¢ط¢آ§ط·آ·ط¢آ¸ط£آ¢أ¢â€ڑآ¬ط¢آ¦ ط·آ·ط¢آ·ط·آ¢ط¢آ®ط·آ·ط¢آ¸ط·آ«أ¢â‚¬آ ط·آ·ط¢آ·ط·آ¢ط¢آ¨ط·آ·ط·â€؛ط·آ¥أ¢â‚¬â„¢ط·آ·ط¢آ·ط·آ¹ط·â€؛', false);
        $this->assertStringContainsString('ط·آ·ط¢آ·ط·آ¢ط¢آ³ط·آ·ط¢آ¸ط£آ¢أ¢â€ڑآ¬أ¢â‚¬ع†ط·آ·ط¢آ·ط·آ¢ط¢آ§ط·آ·ط¢آ¸ط£آ¢أ¢â€ڑآ¬ط¢آ¦ ط·آ·ط¢آ·ط·آ¢ط¢آ®ط·آ·ط¢آ¸ط·آ«أ¢â‚¬آ ط·آ·ط¢آ·ط·آ¢ط¢آ¨ط·آ·ط·â€؛ط·آ¥أ¢â‚¬â„¢ط·آ·ط¢آ·ط·آ¹ط·â€؛', $this->delivered($a));
        $this->assertStringNotContainsString(__('Conversation is unavailable.'), $this->delivered($a).$this->delivered($b));
    }

    public function test_acceptance_sends_active_keyboard_and_text_photo_voice_relay(): void
    {
        [$a, $b, , $conversation] = $this->connect();
        $this->assertSame(2, $conversation->participants()->count());
        foreach ([$a, $b] as $user) {
            $message = collect($this->telegram->sent)->last(
                fn ($item) => ($item['parameters']['chat_id'] ?? null) === $user->telegram_user_id
                    && isset($item['parameters']['reply_markup']['keyboard'])
            );
            $this->assertSame(Keyboard::chatReply(false), $message['parameters']['reply_markup']['keyboard']);
            $this->assertNotSame(Keyboard::homeReply(), $message['parameters']['reply_markup']['keyboard']);
        }

        $this->telegram->sent = [];
        $this->send($a, 'ط·آ·ط¢آ¸ط£آ¢أ¢â€ڑآ¬ط¢آ¦ط·آ·ط¢آ·ط·آ¹ط¢آ¾ط·آ·ط¢آ¸ط£آ¢أ¢â€ڑآ¬ط¢آ  ط·آ·ط¢آ·ط·آ¹ط¢آ¾ط·آ·ط¢آ·ط·آ¢ط¢آ³ط·آ·ط¢آ·ط·آ¹ط¢آ¾', false);
        $this->sendMedia($a, 'photo', 'photo-chat');
        $this->sendMedia($a, 'voice', 'voice-chat');
        $toB = collect($this->telegram->sent)->filter(fn ($m) => ($m['parameters']['chat_id'] ?? null) === $b->telegram_user_id);
        $this->assertTrue($toB->contains(fn ($m) => $m['method'] === 'sendMessage' && ($m['parameters']['text'] ?? '') === 'ط·آ·ط¢آ¸ط£آ¢أ¢â€ڑآ¬ط¢آ¦ط·آ·ط¢آ·ط·آ¹ط¢آ¾ط·آ·ط¢آ¸ط£آ¢أ¢â€ڑآ¬ط¢آ  ط·آ·ط¢آ·ط·آ¹ط¢آ¾ط·آ·ط¢آ·ط·آ¢ط¢آ³ط·آ·ط¢آ·ط·آ¹ط¢آ¾'));
        $this->assertTrue($toB->contains(fn ($m) => $m['method'] === 'sendPhoto' && ($m['parameters']['photo'] ?? '') === 'photo-chat'));
        $this->assertTrue($toB->contains(fn ($m) => $m['method'] === 'sendVoice' && ($m['parameters']['voice'] ?? '') === 'voice-chat'));
        $this->assertDatabaseCount('chat_messages', 3);
    }

    public function test_partner_profile_and_paid_direct_return_to_same_chat(): void
    {
        [$a, $b, , $conversation] = $this->connect();
        $b->profile->update(['photo_file_id' => 'partner-photo', 'voice_file_id' => 'partner-voice']);
        $this->telegram->sent = [];
        $this->send($a, __('View chat partner profile'), false);
        $this->assertTrue(collect($this->telegram->sent)->contains(fn ($m) => $m['method'] === 'sendPhoto' && ($m['parameters']['photo'] ?? '') === 'partner-photo'));
        $this->assertTrue(collect($this->telegram->sent)->contains(fn ($m) => $m['method'] === 'sendVoice' && ($m['parameters']['voice'] ?? '') === 'partner-voice'));
        $visible = json_encode($this->telegram->sent);
        $this->assertStringNotContainsString((string) $b->telegram_user_id, $visible);
        $this->assertStringNotContainsString($b->telegram_username, $visible);

        $before = app(WalletService::class)->wallet($a)->fresh()->balance;
        $this->send($a, __('Direct to chat partner'), false);
        $this->send($a, 'ط·آ·ط¢آ·ط·آ¢ط¢آ¯ط·آ·ط¢آ·ط·آ¢ط¢آ§ط·آ·ط·â€؛ط·آ¥أ¢â‚¬â„¢ط·آ·ط¢آ·ط·آ¢ط¢آ±ط·آ·ط¢آ¹ط·آ¢ط¢آ©ط·آ·ط¢آ·ط·آ¹ط¢آ¾ ط·آ·ط¢آ·ط·آ¹ط¢آ¾ط·آ·ط¢آ·ط·آ¢ط¢آ³ط·آ·ط¢آ·ط·آ¹ط¢آ¾', false);
        $this->assertSame($before, app(WalletService::class)->wallet($a)->fresh()->balance);
        $review = InteractionState::where('user_id', $a->id)->firstOrFail();
        $this->assertSame('direct_review', $review->mode);
        $this->send($a, 's:'.$review->revision.':direct_send');
        $this->assertDatabaseCount('direct_messages', 1);
        $this->assertSame($before - 2, app(WalletService::class)->wallet($a)->fresh()->balance);
        $this->assertDatabaseHas('direct_messages', ['sender_user_id' => $a->id, 'recipient_user_id' => $b->id, 'text' => 'ط·آ·ط¢آ·ط·آ¢ط¢آ¯ط·آ·ط¢آ·ط·آ¢ط¢آ§ط·آ·ط·â€؛ط·آ¥أ¢â‚¬â„¢ط·آ·ط¢آ·ط·آ¢ط¢آ±ط·آ·ط¢آ¹ط·آ¢ط¢آ©ط·آ·ط¢آ·ط·آ¹ط¢آ¾ ط·آ·ط¢آ·ط·آ¹ط¢آ¾ط·آ·ط¢آ·ط·آ¢ط¢آ³ط·آ·ط¢آ·ط·آ¹ط¢آ¾']);
        $state = InteractionState::where('user_id', $a->id)->firstOrFail();
        $this->assertSame('chat', $state->mode);
        $this->assertSame($conversation->id, $state->conversation_id);
        $this->assertSame(ConversationStatus::Active, $conversation->fresh()->status);
    }

    public function test_private_mode_persists_and_protects_every_supported_relay_then_disables(): void
    {
        [$a, $b, , $conversation] = $this->connect();
        $this->telegram->sent = [];
        $this->send($a, __('Enable private chat'), false);
        $this->assertTrue($conversation->fresh()->is_protected);
        foreach ([$a, $b] as $user) {
            $this->assertStringContainsString(__('Private chat enabled.'), $this->delivered($user));
        }

        $this->telegram->sent = [];
        $this->send($a, 'protected text', false);
        $this->sendMedia($a, 'photo', 'protected-photo');
        $this->sendMedia($a, 'voice', 'protected-voice');
        $relay = collect($this->telegram->sent)->filter(fn ($m) => ($m['parameters']['chat_id'] ?? null) === $b->telegram_user_id
            && in_array($m['method'], ['sendMessage', 'sendPhoto', 'sendVoice'], true)
            && ! isset($m['parameters']['reply_markup'])
        );
        $this->assertCount(3, $relay);
        foreach ($relay as $message) {
            $this->assertTrue($message['parameters']['protect_content'] ?? false);
        }
        $this->assertSame(1, DB::table('conversations')->count());

        $this->telegram->sent = [];
        $this->send($b, __('Disable private chat'), false);
        $this->assertFalse($conversation->fresh()->is_protected);
        $this->send($a, 'normal again', false);
        $normal = collect($this->telegram->sent)->first(fn ($m) => ($m['parameters']['chat_id'] ?? null) === $b->telegram_user_id
            && ($m['parameters']['text'] ?? null) === 'normal again'
        );
        $this->assertArrayNotHasKey('protect_content', $normal['parameters']);
        $this->assertSame(3, DB::table('chat_messages')->where('is_protected', true)->count());
    }

    public function test_end_cancel_and_confirm_restore_state_and_release_users(): void
    {
        [$a, $b, , $conversation] = $this->connect();
        $this->send($a, __('Enable private chat'), false);
        $this->send($a, __('End chat'), false);
        $state = InteractionState::where('user_id', $a->id)->firstOrFail();
        $this->send($a, 's:'.$state->revision.':cancel_close_chat_'.$conversation->id);
        $this->assertSame(ConversationStatus::Active, $conversation->fresh()->status);
        $this->assertTrue($conversation->fresh()->is_protected);

        $state->refresh();
        $this->send($a, 's:'.$state->revision.':close_chat');
        $state->refresh();
        $this->send($a, 's:'.$state->revision.':confirm_close_chat_'.$conversation->id);
        $this->assertSame(ConversationStatus::Closed, $conversation->fresh()->status);
        $this->assertFalse($conversation->fresh()->is_protected);
        foreach ([$a, $b] as $user) {
            $interaction = InteractionState::where('user_id', $user->id)->firstOrFail();
            $this->assertSame('menu', $interaction->mode);
            $this->assertNull($interaction->conversation_id);
            $this->assertNull(app(ConversationService::class)->activeFor($user));
        }
        $this->assertNotNull(app(ChatRequestService::class)->create($a, $b));
    }
}
