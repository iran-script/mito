<?php

namespace Tests\Feature;

use App\Domain\Chat\ChatRequestService;
use App\Domain\Chat\ConversationService;
use App\Domain\Chat\ConversationStatus;
use App\Domain\Chat\ConversationTelegramMessage;
use App\Domain\Payments\WalletService;
use App\Domain\Telegram\InteractionState;
use App\Domain\Telegram\Keyboard;
use App\Domain\Users\MitoId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithGuessGames;
use Tests\TestCase;

class UniversalEndChatTest extends TestCase
{
    use InteractsWithGuessGames, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootGameTests();
        app()->setLocale('fa');
    }

    public static function origins(): array
    {
        return [
            'manual profile request' => ['manual'],
            'anonymous matchmaking' => ['anonymous'],
            'same city' => ['same_city'],
            'same age' => ['same_age'],
            'interest' => ['interest'],
            'new users' => ['new_users'],
            'nearby' => ['nearby'],
        ];
    }

    private function activePair(string $origin): array
    {
        $a = $this->user('First');
        $b = $this->user('Second');
        app(WalletService::class)->wallet($a)->update(['balance' => 100]);

        if ($origin === 'anonymous') {
            $conversation = app(ConversationService::class)->connectAnonymous($a->id, $b->id);
            foreach ([[$a, $b], [$b, $a]] as [$user, $other]) {
                DB::table('matchmaking_searches')->insert([
                    'user_id' => $user->id,
                    'gender' => $other->profile->gender->value,
                    'status' => 'matched',
                    'generation' => 1,
                    'matched_user_id' => $other->id,
                    'started_at' => now(),
                    'expires_at' => now()->addMinutes(2),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        } else {
            $requests = app(ChatRequestService::class);
            $conversation = $requests->accept($b, $requests->create($a, $b));
        }

        foreach ([$a, $b] as $user) {
            InteractionState::updateOrCreate(['user_id' => $user->id], [
                'mode' => 'chat',
                'conversation_id' => $conversation->id,
                'direct_recipient_id' => $user->id,
                'direct_context' => ['draft' => 'temporary'],
                'bulk_mode' => 'select',
                'bulk_selection' => [$user->id],
                'bulk_context' => ['temporary' => true],
                'event_context' => ['step' => 'temporary'],
                'game_context' => ['temporary' => true],
            ]);
        }

        return [$a, $b, $conversation];
    }

    #[DataProvider('origins')]
    public function test_every_origin_uses_one_authoritative_two_sided_end_flow(string $origin): void
    {
        [$a, $b, $conversation] = $this->activePair($origin);
        $conversation->update(['is_protected' => true]);

        $this->send($a, __('End chat'), false);
        $state = InteractionState::where('user_id', $a->id)->firstOrFail();
        $this->telegram->sent = [];
        $this->send($a, 's:'.$state->revision.':confirm_close_chat_'.$conversation->id);

        $this->assertSame(ConversationStatus::Closed, $conversation->fresh()->status);
        $this->assertFalse($conversation->fresh()->is_protected);
        foreach ([$a, $b] as $user) {
            $interaction = InteractionState::where('user_id', $user->id)->firstOrFail();
            $this->assertSame('menu', $interaction->mode);
            $this->assertNull($interaction->conversation_id);
            $this->assertNull($interaction->direct_recipient_id);
            $this->assertNull($interaction->direct_context);
            $this->assertNull($interaction->bulk_mode);
            $this->assertNull($interaction->event_context);
            $this->assertNull($interaction->game_context);
            $this->assertNull(app(ConversationService::class)->activeFor($user));

            $other = $user->is($a) ? $b : $a;
            $ended = collect($this->telegram->sent)->first(fn ($message) => ($message['parameters']['chat_id'] ?? null) === $user->telegram_user_id
                && ($message['parameters']['text'] ?? null) === __('Your chat with :mito_id ended.', ['mito_id' => MitoId::display($other->public_mito_id)])
            );
            $this->assertNotNull($ended);
            $rows = $ended['parameters']['reply_markup']['inline_keyboard'];
            $this->assertCount(1, $rows);
            $this->assertCount(1, $rows[0]);
            $this->assertSame(__('Delete this chat messages'), $rows[0][0]['text']);
            $this->assertSame('n:0:cleanup_chat_'.$conversation->id, $rows[0][0]['callback_data']);
            $this->assertTrue(collect($this->telegram->sent)->contains(fn ($message) => ($message['parameters']['chat_id'] ?? null) === $user->telegram_user_id
                && ($message['parameters']['reply_markup']['keyboard'] ?? null) === Keyboard::homeReply()
            ));
        }
        $this->assertSame(2, DB::table('social_outbox')->where('source_type', 'chat_ended')->where('source_id', $conversation->id)->count());
        if ($origin === 'anonymous') {
            $this->assertSame(0, DB::table('matchmaking_searches')->whereIn('user_id', [$a->id, $b->id])->whereIn('status', ['waiting', 'matched'])->count());
        }
        $this->assertNotNull(app(ChatRequestService::class)->create($a, $b));
    }

    public function test_cancel_keeps_chat_and_duplicate_confirm_is_idempotent(): void
    {
        [$a, $b, $conversation] = $this->activePair('manual');
        $this->send($a, __('End chat'), false);
        $state = InteractionState::where('user_id', $a->id)->firstOrFail();
        $this->send($a, 's:'.$state->revision.':cancel_close_chat_'.$conversation->id);
        $this->assertSame(ConversationStatus::Active, $conversation->fresh()->status);
        $this->assertStringContainsString(__('The chat continues.'), $this->delivered($a));

        $state->refresh();
        $this->send($a, __('End chat'), false);
        $state->refresh();
        $callback = 's:'.$state->revision.':confirm_close_chat_'.$conversation->id;
        $this->send($a, $callback);
        $outboxCount = DB::table('social_outbox')->where('source_type', 'chat_ended')->where('source_id', $conversation->id)->count();
        $this->telegram->sent = [];
        $this->send($a, $callback);
        $this->assertSame($outboxCount, DB::table('social_outbox')->where('source_type', 'chat_ended')->where('source_id', $conversation->id)->count());
        $this->assertStringContainsString(__('This chat already ended.'), $this->delivered($a));
        $this->assertSame(ConversationStatus::Closed, $conversation->fresh()->status);
        $this->assertNull(app(ConversationService::class)->activeFor($b));
    }

    public function test_cleanup_attempts_both_sides_continues_after_failure_and_excludes_other_conversation(): void
    {
        [$a, $b, $conversation] = $this->activePair('anonymous');
        $this->send($a, __('End chat'), false);
        $state = InteractionState::where('user_id', $a->id)->firstOrFail();
        $this->send($a, 's:'.$state->revision.':confirm_close_chat_'.$conversation->id);

        foreach ([[5101, $a->id, $a->telegram_user_id, 'incoming'], [5102, $b->id, $b->telegram_user_id, 'outgoing'], [5103, $a->id, $a->telegram_user_id, 'outgoing']] as [$messageId, $userId, $chatId, $direction]) {
            ConversationTelegramMessage::create([
                'conversation_id' => $conversation->id,
                'user_id' => $userId,
                'telegram_chat_id' => $chatId,
                'telegram_message_id' => $messageId,
                'direction' => $direction,
            ]);
        }
        $c = $this->user('Third');
        $d = $this->user('Fourth');
        $otherConversation = app(ConversationService::class)->connectAnonymous($c->id, $d->id);
        ConversationTelegramMessage::create([
            'conversation_id' => $otherConversation->id,
            'user_id' => $c->id,
            'telegram_chat_id' => $c->telegram_user_id,
            'telegram_message_id' => 5999,
            'direction' => 'incoming',
        ]);

        $this->telegram->failDeleteMessageIds = [5102];
        $this->telegram->sent = [];
        $this->send($b, 'n:0:cleanup_chat_'.$conversation->id);

        $attempted = collect($this->telegram->sent)->where('method', 'deleteMessage')->pluck('parameters.message_id')->all();
        $this->assertContains(5101, $attempted);
        $this->assertContains(5102, $attempted);
        $this->assertContains(5103, $attempted);
        $this->assertNotContains(5999, $attempted);
        $this->assertDatabaseHas('conversation_telegram_messages', ['conversation_id' => $conversation->id, 'telegram_message_id' => 5101, 'delete_status' => 'deleted']);
        $this->assertDatabaseHas('conversation_telegram_messages', ['conversation_id' => $conversation->id, 'telegram_message_id' => 5102, 'delete_status' => 'failed']);
        $this->assertDatabaseHas('conversation_telegram_messages', ['conversation_id' => $conversation->id, 'telegram_message_id' => 5103, 'delete_status' => 'deleted']);
        $this->assertDatabaseHas('conversation_telegram_messages', ['conversation_id' => $otherConversation->id, 'telegram_message_id' => 5999, 'delete_status' => 'pending']);
        $this->assertStringContainsString(__('The removable conversation messages were cleared. Some older messages may remain.'), $this->delivered($b));
        $this->assertDatabaseCount('chat_messages', 0);
    }
}
