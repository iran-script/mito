<?php

namespace App\Domain\Telegram;

use App\Domain\Chat\ChatMessage;
use App\Domain\Direct\DirectMessage;
use App\Domain\Telegram\Jobs\DeliverSocialNotification;
use App\Domain\Users\MitoId;
use App\Domain\Users\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class SocialNotificationService
{
    public function queue(User $recipient, string $sourceType, int $sourceId, string $text, ?string $idempotencyKey = null, array $keyboard = []): void
    {
        $key = $idempotencyKey ?? $sourceType.':'.$sourceId.':'.$recipient->id;
        $parameters = ['chat_id' => $recipient->telegram_user_id, 'text' => $text];
        if ($keyboard) {
            $parameters['reply_markup'] = ['inline_keyboard' => $keyboard];
        }
        Keyboard::assertValidPayload(['method' => 'sendMessage', 'parameters' => $parameters]);
        $id = DB::table('social_outbox')->insertOrIgnore(['recipient_user_id' => $recipient->id, 'source_type' => $sourceType, 'source_id' => $sourceId, 'idempotency_key' => $key, 'payload' => Crypt::encryptString(json_encode(['method' => 'sendMessage', 'parameters' => $parameters], JSON_THROW_ON_ERROR)), 'created_at' => now(), 'updated_at' => now()]);
        if ($id) {
            DeliverSocialNotification::dispatch(DB::table('social_outbox')->where('idempotency_key', $key)->value('id'))->onConnection('database')->onQueue('telegram-outbound');
        }
    }

    public function queuePayload(User $recipient, string $sourceType, int $sourceId, array $payload, ?string $idempotencyKey = null): void
    {
        Keyboard::assertValidPayload($payload);
        $key = $idempotencyKey ?? $sourceType.':'.$sourceId.':'.$recipient->id;
        $id = DB::table('social_outbox')->insertOrIgnore([
            'recipient_user_id' => $recipient->id,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'idempotency_key' => $key,
            'payload' => Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        if ($id) {
            DeliverSocialNotification::dispatch(DB::table('social_outbox')->where('idempotency_key', $key)->value('id'))
                ->onConnection('database')->onQueue('telegram-outbound');
        }
    }

    public function queueReply(User $recipient, string $sourceType, int $sourceId, string $text, array $rows, ?string $idempotencyKey = null): void
    {
        $this->queuePayload($recipient, $sourceType, $sourceId, [
            'method' => 'sendMessage',
            'parameters' => [
                'chat_id' => $recipient->telegram_user_id,
                'text' => $text,
                'reply_markup' => Keyboard::reply($rows, true),
            ],
        ], $idempotencyKey);
    }

    public function request(User $recipient, int $requestId): void
    {
        $this->queue($recipient, 'chat_request', $requestId, __('You have a new chat request.'), null, [
            [Keyboard::button('n', 0, __('View request'), 'view_request_'.$requestId)],
        ]);
    }

    public function seen(User $requester, int $requestId, User $recipient): void
    {
        $this->queue($requester, 'chat_request_seen', $requestId, __('Your chat request was seen by :mito_id.', [
            'mito_id' => MitoId::display($recipient->public_mito_id),
        ]), 'chat_request_seen:'.$requestId);
    }

    public function accepted(User $requester, int $requestId, User $recipient): void
    {
        $this->queueReply($requester, 'chat_request_accepted', $requestId, __('Your chat request was accepted. You can now chat with :mito_id.', [
            'mito_id' => MitoId::display($recipient->public_mito_id),
        ]), Keyboard::chatReply(false));
    }

    public function rejected(User $requester, int $requestId, User $recipient): void
    {
        $this->queue($requester, 'chat_request_rejected', $requestId, __('Your chat request was rejected by :mito_id.', [
            'mito_id' => MitoId::display($recipient->public_mito_id),
        ]));
    }

    public function directMessage(User $recipient, DirectMessage $message): void
    {
        $this->queue($recipient, 'direct_message', $message->id, __('You have a new direct message.'), null, [
            [Keyboard::button('n', 0, __('View message'), 'open_direct_'.$message->id)],
        ]);
    }

    public function directSeen(User $sender, DirectMessage $message, User $recipient): void
    {
        $this->queue($sender, 'direct_message_seen', $message->id, __('Your message to :mito_id was read.', [
            'mito_id' => MitoId::display($recipient->public_mito_id),
        ]), 'direct_message_seen:'.$message->id);
    }

    public function chatEnded(User $recipient, int $conversationId, User $other, string $eventKey): void
    {
        $text = __('Your chat with :mito_id ended.', ['mito_id' => MitoId::display($other->public_mito_id)]);
        $this->queue($recipient, 'chat_ended', $conversationId, $text, 'chat_ended:'.$conversationId.':'.$eventKey.':'.$recipient->id, [
            [Keyboard::button('n', 0, __('Delete this chat messages'), 'cleanup_chat_'.$conversationId, 'danger')],
        ]);
        $this->queueReply($recipient, 'chat_home', $conversationId, __('You are back on the home screen.'), Keyboard::homeReply(), 'chat_home:'.$conversationId.':'.$eventKey.':'.$recipient->id);
    }

    public function chatMessage(User $recipient, ChatMessage $message): void
    {
        $this->queuePayload(
            $recipient,
            'chat_message',
            $message->id,
            app(ChatRelayBuilder::class)->build($recipient, $message),
        );
    }
}
