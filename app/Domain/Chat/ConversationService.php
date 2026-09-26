<?php

namespace App\Domain\Chat;

use App\Domain\Moderation\ContactInformationGuard;
use App\Domain\Payments\CoinTransactionType;
use App\Domain\Payments\WalletService;
use App\Domain\Telegram\SocialNotificationService;
use App\Domain\Users\BlockService;
use App\Domain\Users\User;
use App\Domain\Users\UserStatus;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ConversationService
{
    public function __construct(private readonly ContactInformationGuard $guard, private readonly BlockService $blocks) {}

    public function lockUsers(int ...$ids): void
    {
        User::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
    }

    public function connectAnonymous(int $firstUserId, int $secondUserId): Conversation
    {
        return DB::transaction(function () use ($firstUserId, $secondUserId) {
            $this->lockUsers($firstUserId, $secondUserId);
            if ($this->activeForEither($firstUserId, $secondUserId)) {
                throw new \DomainException('One of you already has an active chat.');
            }
            [$low, $high] = $firstUserId < $secondUserId
                ? [$firstUserId, $secondUserId]
                : [$secondUserId, $firstUserId];
            $conversation = Conversation::where([
                'user_low_id' => $low,
                'user_high_id' => $high,
            ])->lockForUpdate()->first();
            if ($conversation) {
                $conversation->update([
                    'status' => ConversationStatus::Active,
                    'is_protected' => false,
                    'origin' => 'anonymous',
                    'chat_request_id' => null,
                    'accepted_by_user_id' => null,
                    'activated_at' => now(),
                    'engagement_rewarded_at' => null,
                ]);
            } else {
                $conversation = Conversation::create([
                    'user_low_id' => $low,
                    'user_high_id' => $high,
                    'status' => ConversationStatus::Active,
                    'is_protected' => false,
                    'origin' => 'anonymous',
                    'activated_at' => now(),
                ]);
            }
            $conversation->participants()->syncWithoutDetaching([$low, $high]);

            return $conversation->fresh();
        }, 3);
    }

    public function activeFor(User|int $user): ?Conversation
    {
        $id = $user instanceof User ? $user->id : $user;

        return Conversation::where('status', ConversationStatus::Active)
            ->where(fn ($query) => $query->where('user_low_id', $id)->orWhere('user_high_id', $id))
            ->first();
    }

    public function activeForEither(int $first, int $second): bool
    {
        return Conversation::where('status', ConversationStatus::Active)
            ->where(function ($query) use ($first, $second) {
                $query->whereIn('user_low_id', [$first, $second])->orWhereIn('user_high_id', [$first, $second]);
            })->exists();
    }

    /**
     * @return array{conversation: Conversation, participants: Collection, ended: bool, event_key: string}
     */
    public function endConversation(User $user, Conversation $conversation): array
    {
        return DB::transaction(function () use ($user, $conversation) {
            $conversation = Conversation::whereKey($conversation->id)->lockForUpdate()->firstOrFail();
            $this->lockUsers($conversation->user_low_id, $conversation->user_high_id);
            $participants = $conversation->participants()->orderBy('users.id')->get();
            if (! $participants->contains('id', $user->id)) {
                throw new \DomainException('Not a participant.');
            }

            $ended = $conversation->status === ConversationStatus::Active;
            if ($ended) {
                $conversation->update(['status' => ConversationStatus::Closed, 'is_protected' => false]);
                $ids = $participants->pluck('id');
                DB::table('interaction_states')->whereIn('user_id', $ids)->update([
                    'mode' => 'menu',
                    'conversation_id' => null,
                    'direct_recipient_id' => null,
                    'direct_context' => null,
                    'bulk_mode' => null,
                    'bulk_selection' => null,
                    'bulk_context' => null,
                    'event_context' => null,
                    'game_context' => null,
                    'updated_at' => now(),
                ]);
                DB::table('matchmaking_searches')->whereIn('user_id', $ids)
                    ->whereIn('status', ['waiting', 'matched'])
                    ->update([
                        'status' => 'cancelled',
                        'matched_user_id' => null,
                        'updated_at' => now(),
                    ]);
                $conversation->refresh();
            }

            return [
                'conversation' => $conversation,
                'participants' => $participants,
                'ended' => $ended,
                'event_key' => $conversation->updated_at->format('Uu'),
            ];
        }, 3);
    }

    public function close(User $user, Conversation $conversation): Conversation
    {
        return $this->endConversation($user, $conversation)['conversation'];
    }

    public function setProtected(User $user, Conversation $conversation, bool $enabled): Conversation
    {
        return DB::transaction(function () use ($user, $conversation, $enabled) {
            $conversation = Conversation::whereKey($conversation->id)->lockForUpdate()->firstOrFail();
            if (! $this->canAccess($user, $conversation)) {
                throw new \DomainException('Conversation is unavailable.');
            }
            if ($conversation->is_protected !== $enabled) {
                $conversation->update(['is_protected' => $enabled]);
            }

            return $conversation->fresh();
        });
    }

    public function send(User $sender, Conversation $conversation, MessageType $type, ?string $text = null, ?string $fileId = null, ?string $idempotencyKey = null): ChatMessage
    {
        return DB::transaction(function () use ($sender, $conversation, $type, $text, $fileId, $idempotencyKey) {
            $conversation = Conversation::whereKey($conversation->id)->lockForUpdate()->firstOrFail();
            if ($conversation->status !== ConversationStatus::Active || ! $conversation->participants()->whereKey($sender->id)->exists() || $this->blockedParticipant($sender, $conversation)) {
                throw new \DomainException('Conversation is unavailable.');
            }
            if ($type === MessageType::Text) {
                $text = trim((string) $text);
                if ($text === '' || mb_strlen($text) > (int) config('social.chat_message_max_length', 4000)) {
                    throw new \DomainException('Message text is invalid.');
                } if ($this->guard->blocked($text)) {
                    throw new \DomainException('Telegram IDs and contact links cannot be exchanged here. Use Share Telegram ID when available.');
                } $fileId = null;
            } elseif (! $fileId) {
                throw new \DomainException('Media file is missing.');
            }
            $key = $idempotencyKey ?? bin2hex(random_bytes(16));
            $existing = ChatMessage::where('idempotency_key', $key)->first();
            if ($existing) {
                return $existing;
            }
            $message = ChatMessage::create(['conversation_id' => $conversation->id, 'sender_user_id' => $sender->id, 'message_type' => $type, 'text' => $text, 'telegram_file_id' => $fileId, 'is_protected' => $conversation->is_protected, 'sent_at' => now(), 'idempotency_key' => $key]);
            $conversation->touch();
            $this->rewardManualChatAtThreshold($conversation);

            return $message;
        });
    }

    private function rewardManualChatAtThreshold(Conversation $conversation): void
    {
        if ($conversation->origin !== 'manual_request' || ! $conversation->chat_request_id || ! $conversation->accepted_by_user_id || $conversation->engagement_rewarded_at) {
            return;
        }
        $count = ChatMessage::where('conversation_id', $conversation->id)
            ->when($conversation->activated_at, fn ($query) => $query->where('sent_at', '>=', $conversation->activated_at))
            ->count();
        if ($count < 10) {
            return;
        }

        $request = ChatRequest::find($conversation->chat_request_id);
        if (! $request || $request->recipient_user_id !== $conversation->accepted_by_user_id) {
            return;
        }
        $transaction = app(WalletService::class)->credit(
            $conversation->accepted_by_user_id,
            1,
            CoinTransactionType::ChatEngagementReward,
            'chat_engagement_reward',
            [
                'reference_type' => Conversation::class,
                'reference_id' => $conversation->id,
                'idempotency_key' => 'chat_engagement_reward:'.$request->id,
                'description' => '🎁 پاداش گفت‌وگوی فعال',
                'metadata' => [
                    'conversation_id' => $conversation->id,
                    'request_id' => $request->id,
                    'requester_user_id' => $request->requester_user_id,
                    'accepter_user_id' => $request->recipient_user_id,
                    'threshold' => 10,
                ],
            ],
        );
        $conversation->update(['engagement_rewarded_at' => now()]);
        $recipient = User::findOrFail($conversation->accepted_by_user_id);
        DB::afterCommit(fn () => app(SocialNotificationService::class)->queue(
            $recipient,
            'chat_engagement_reward',
            $conversation->id,
            __('Chat engagement reward received.', ['balance' => $transaction->balance_after]),
            'chat_engagement_reward:'.$request->id,
        ));
    }

    public function markRead(User $reader, Conversation $conversation): int
    {
        if (! $this->canAccess($reader, $conversation->fresh())) {
            throw new \DomainException('Not a participant.');
        }
        $count = ChatMessage::where('conversation_id', $conversation->id)->where('sender_user_id', '<>', $reader->id)->whereNull('read_at')->update(['read_at' => now(), 'updated_at' => now()]);
        DB::table('conversation_participants')->where(['conversation_id' => $conversation->id, 'user_id' => $reader->id])->update(['last_read_at' => now()]);

        return $count;
    }

    public function unreadCount(User $user, Conversation $conversation): int
    {
        return ChatMessage::where('conversation_id', $conversation->id)->where('sender_user_id', '<>', $user->id)->whereNull('read_at')->count();
    }

    public function canAccess(User $user, Conversation $conversation): bool
    {
        return $conversation->status === ConversationStatus::Active && $conversation->participants()->whereKey($user->id)->exists() && ! $this->blockedParticipant($user, $conversation);
    }

    private function blockedParticipant(User $user, Conversation $conversation): bool
    {
        $other = $conversation->participants()->where('users.id', '<>', $user->id)->first();

        return ! $other || $user->fresh()->status !== UserStatus::Active || $other->status !== UserStatus::Active || $this->blocks->isBlocked($user, $other);
    }
}
