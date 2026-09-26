<?php

namespace Tests\Feature;

use App\Domain\Chat\ChatRequest;
use App\Domain\Chat\ChatRequestService;
use App\Domain\Chat\ChatRequestStatus;
use App\Domain\Payments\WalletService;
use App\Domain\Telegram\InteractionState;
use App\Domain\Users\MitoId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithGuessGames;
use Tests\TestCase;

class ChatRequestRecipientUxTest extends TestCase
{
    use InteractsWithGuessGames, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootGameTests();
        app()->setLocale('fa');
    }

    public function test_notification_view_full_profile_and_seen_are_idempotent(): void
    {
        $a = $this->user('Requester');
        $b = $this->user('Recipient');
        $a->profile->update(['photo_file_id' => 'request-photo', 'voice_file_id' => 'request-voice']);

        $this->send($a, 's:0:request_'.$b->id);
        $request = ChatRequest::sole();
        $this->assertNull($request->seen_at);
        $notification = collect($this->telegram->sent)->first(
            fn ($message) => ($message['parameters']['chat_id'] ?? null) === $b->telegram_user_id
        );
        $rows = $notification['parameters']['reply_markup']['inline_keyboard'];
        $this->assertSame(__('You have a new chat request.'), $notification['parameters']['text']);
        $this->assertCount(1, $rows);
        $this->assertCount(1, $rows[0]);
        $this->assertSame(__('View request'), $rows[0][0]['text']);

        $this->telegram->sent = [];
        $this->send($b, $rows[0][0]['callback_data']);
        $request->refresh();
        $this->assertSame(ChatRequestStatus::Seen, $request->status);
        $this->assertNotNull($request->seen_at);
        $seenAt = $request->seen_at;
        $this->assertSame(1, DB::table('social_outbox')->where('source_type', 'chat_request_seen')->count());
        $photo = collect($this->telegram->sent)->firstWhere('method', 'sendPhoto');
        $voice = collect($this->telegram->sent)->firstWhere('method', 'sendVoice');
        $this->assertSame('request-photo', $photo['parameters']['photo']);
        $this->assertSame('request-voice', $voice['parameters']['voice']);
        $caption = $photo['parameters']['caption'];
        $this->assertStringContainsString('Requester', $caption);
        $this->assertStringContainsString(MitoId::display($a->public_mito_id), $caption);
        $this->assertStringNotContainsString((string) $a->telegram_user_id, $caption);
        $this->assertStringNotContainsString($a->telegram_username, $caption);
        $decision = $photo['parameters']['reply_markup']['inline_keyboard'];
        $this->assertSame([__('Accept request'), __('Reject request')], array_column($decision[0], 'text'));

        $this->send($b, $rows[0][0]['callback_data']);
        $this->assertEquals($seenAt, $request->fresh()->seen_at);
        $this->assertSame(1, DB::table('social_outbox')->where('source_type', 'chat_request_seen')->count());
    }

    public function test_reject_is_idempotent_free_and_notifies_requester_with_public_id(): void
    {
        $a = $this->user('Requester');
        $b = $this->user('Recipient');
        app(WalletService::class)->wallet($a)->update(['balance' => 10]);
        $this->send($a, 's:0:request_'.$b->id);
        $request = ChatRequest::sole();
        $this->send($b, 'n:0:view_request_'.$request->id);
        $revision = InteractionState::where('user_id', $b->id)->value('revision');
        $before = app(WalletService::class)->wallet($a)->fresh()->balance;

        $this->telegram->sent = [];
        $this->send($b, 's:'.$revision.':reject_request_'.$request->id);
        $this->assertSame(ChatRequestStatus::Rejected, $request->fresh()->status);
        $this->assertNotNull($request->fresh()->rejected_at);
        $this->assertSame($before, app(WalletService::class)->wallet($a)->fresh()->balance);
        $this->assertDatabaseCount('conversations', 0);
        $this->assertStringContainsString(MitoId::display($b->public_mito_id), $this->delivered($a));
        $this->assertSame('menu', InteractionState::where('user_id', $b->id)->value('mode'));

        app(ChatRequestService::class)->reject($b, $request->fresh());
        $this->assertSame(1, DB::table('social_outbox')->where('source_type', 'chat_request_rejected')->count());
    }

    public function test_accept_charges_requester_once_and_enters_chat_for_both(): void
    {
        $a = $this->user('Requester');
        $b = $this->user('Recipient');
        app(WalletService::class)->wallet($a)->update(['balance' => 10]);
        app(WalletService::class)->wallet($b)->update(['balance' => 99]);
        $this->send($a, 's:0:request_'.$b->id);
        $request = ChatRequest::sole();
        $this->send($b, 'n:0:view_request_'.$request->id);
        $revision = InteractionState::where('user_id', $b->id)->value('revision');

        $this->telegram->sent = [];
        $this->send($b, 's:'.$revision.':accept_request_'.$request->id);
        $conversationId = DB::table('conversations')->value('id');
        $this->assertSame(8, app(WalletService::class)->wallet($a)->fresh()->balance);
        $this->assertSame(99, app(WalletService::class)->wallet($b)->fresh()->balance);
        $this->assertDatabaseCount('coin_transactions', 1);
        foreach ([$a, $b] as $participant) {
            $state = InteractionState::where('user_id', $participant->id)->firstOrFail();
            $this->assertSame('chat', $state->mode);
            $this->assertSame($conversationId, $state->conversation_id);
        }
        $this->assertStringContainsString(MitoId::display($b->public_mito_id), $this->delivered($a));
        $this->assertStringContainsString(__('Chat connected. Send your message here.'), $this->delivered($b));

        app(ChatRequestService::class)->accept($b, $request->fresh());
        $this->assertDatabaseCount('conversations', 1);
        $this->assertDatabaseCount('coin_transactions', 1);
    }

    public function test_non_recipient_cannot_view_accept_or_reject(): void
    {
        $a = $this->user('Requester');
        $b = $this->user('Recipient');
        $outsider = $this->user('Outsider');
        $request = app(ChatRequestService::class)->create($a, $b);

        foreach (['view', 'accept', 'reject'] as $method) {
            try {
                app(ChatRequestService::class)->{$method}($outsider, $request);
                $this->fail('Expected recipient ownership rejection.');
            } catch (\DomainException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }
        $this->assertSame(ChatRequestStatus::Pending, $request->fresh()->status);
    }
}
