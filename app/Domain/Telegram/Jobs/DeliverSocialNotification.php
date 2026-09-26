<?php

namespace App\Domain\Telegram\Jobs;

use App\Domain\Chat\ChatMessage;
use App\Domain\Chat\ConversationTelegramMessage;
use App\Domain\Games\GameSession;
use App\Domain\Games\GameStatus;
use App\Domain\Telegram\TelegramClient;
use App\Domain\Users\BlockService;
use App\Domain\Users\UserStatus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class DeliverSocialNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 30;

    public function __construct(public int $outboxId) {}

    public function backoff(): array
    {
        return [5, 30, 120, 300];
    }

    public function handle(TelegramClient $client): void
    {
        DB::transaction(function () use ($client) {
            $row = DB::table('social_outbox')->where('id', $this->outboxId)->lockForUpdate()->first();
            if (! $row || $row->sent_at) {
                return;
            }
            if (in_array($row->source_type, ['two_truths', 'guess_interest', 'guess_number', 'game_invite', 'game_accepted', 'game_started', 'game_turn', 'game_result', 'game_mutual'], true)) {
                $session = GameSession::find($row->source_id);
                $players = $session?->participants()->get();
                if (! $session || $players->count() !== 2 || $players->contains(fn ($p) => $p->status !== UserStatus::Active) || in_array($session->status, [GameStatus::Cancelled, GameStatus::Expired], true) || ($session->status !== GameStatus::Completed && $session->expires_at?->isPast()) || app(BlockService::class)->isBlocked($players[0], $players[1])) {
                    DB::table('social_outbox')->where('id', $row->id)->update(['sent_at' => now(), 'payload' => '', 'updated_at' => now()]);

                    return;
                }
            }
            $payload = json_decode(Crypt::decryptString($row->payload), true, 512, JSON_THROW_ON_ERROR);
            $result = $client->send($payload['method'], $payload['parameters']);
            DB::table('social_outbox')->where('id', $row->id)->update(['sent_at' => now(), 'payload' => '', 'updated_at' => now()]);
            if ($row->source_type === 'chat_message') {
                $message = ChatMessage::find($row->source_id);
                DB::table('chat_messages')->where('id', $row->source_id)->whereNull('delivered_at')->update(['delivered_at' => now(), 'updated_at' => now()]);
                if ($message && isset($result['message_id'])) {
                    $recipient = DB::table('users')->where('id', $row->recipient_user_id)->first();
                    if ($recipient) {
                        ConversationTelegramMessage::firstOrCreate(
                            ['telegram_chat_id' => $recipient->telegram_user_id, 'telegram_message_id' => $result['message_id']],
                            ['conversation_id' => $message->conversation_id, 'chat_message_id' => $message->id, 'user_id' => $recipient->id, 'direction' => 'outgoing']
                        );
                    }
                }
            } elseif ($row->source_type === 'chat_ended' && isset($result['message_id'])) {
                $recipient = DB::table('users')->where('id', $row->recipient_user_id)->first();
                if ($recipient) {
                    ConversationTelegramMessage::firstOrCreate(
                        ['telegram_chat_id' => $recipient->telegram_user_id, 'telegram_message_id' => $result['message_id']],
                        ['conversation_id' => $row->source_id, 'user_id' => $recipient->id, 'direction' => 'status']
                    );
                }
            }
        });
    }
}
