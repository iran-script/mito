<?php

namespace App\Domain\Chat;

use App\Domain\Telegram\Jobs\CleanupConversationMessages;
use App\Domain\Users\User;
use Illuminate\Support\Facades\DB;

class ConversationCleanupService
{
    public function request(User $user, Conversation $conversation): string
    {
        return DB::transaction(function () use ($user, $conversation) {
            $conversation = Conversation::whereKey($conversation->id)->lockForUpdate()->firstOrFail();
            if (! $conversation->participants()->whereKey($user->id)->exists()) {
                throw new \DomainException('Only conversation participants can clean it up.');
            }
            if ($conversation->status !== ConversationStatus::Closed) {
                throw new \DomainException('End the conversation before cleaning up its messages.');
            }
            if ($conversation->telegram_cleanup_status === 'completed') {
                return 'completed';
            }
            if ($conversation->telegram_cleanup_status !== 'processing') {
                $conversation->update([
                    'telegram_cleanup_status' => 'processing',
                    'telegram_cleanup_requested_by' => $user->id,
                    'telegram_cleanup_requested_at' => now(),
                ]);
                CleanupConversationMessages::dispatch($conversation->id, $user->id)
                    ->onConnection('database')->onQueue('telegram-outbound');
            }

            return 'processing';
        });
    }
}
