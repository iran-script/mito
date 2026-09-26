<?php

namespace App\Domain\Telegram\Jobs;

use App\Domain\Chat\Conversation;
use App\Domain\Chat\ConversationTelegramMessage;
use App\Domain\Telegram\SocialNotificationService;
use App\Domain\Telegram\TelegramClient;
use App\Domain\Users\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class CleanupConversationMessages implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public int $conversationId, public int $requesterId) {}

    public function handle(TelegramClient $client, SocialNotificationService $notifications): void
    {
        $conversation = Conversation::find($this->conversationId);
        $requester = User::find($this->requesterId);
        if (! $conversation || ! $requester || ! $conversation->participants()->whereKey($requester->id)->exists()) {
            return;
        }
        if ($conversation->telegram_cleanup_status === 'completed') {
            $notifications->queue($requester, 'chat_cleanup_result', $conversation->id, __('Conversation messages were already cleaned up.'), 'chat_cleanup_already:'.$conversation->id.':'.$requester->id);

            return;
        }

        $failed = 0;
        ConversationTelegramMessage::where('conversation_id', $conversation->id)
            ->where('delete_status', 'pending')->orderBy('id')->chunkById(100, function ($messages) use ($client, &$failed) {
                foreach ($messages as $message) {
                    try {
                        $client->send('deleteMessage', ['chat_id' => $message->telegram_chat_id, 'message_id' => $message->telegram_message_id]);
                        $message->update(['delete_status' => 'deleted', 'deleted_at' => now()]);
                    } catch (Throwable) {
                        $failed++;
                        $message->update(['delete_status' => 'failed']);
                    }
                }
            });

        $conversation->update(['telegram_cleanup_status' => 'completed', 'telegram_cleanup_completed_at' => now()]);
        $text = $failed === 0
            ? __('Conversation messages were removed for both people.')
            : __('The removable conversation messages were cleared. Some older messages may remain.');
        $notifications->queue($requester, 'chat_cleanup_result', $conversation->id, $text, 'chat_cleanup_result:'.$conversation->id.':'.$requester->id);
    }
}
