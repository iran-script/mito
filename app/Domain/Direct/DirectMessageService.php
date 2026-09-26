<?php

namespace App\Domain\Direct;

use App\Domain\Moderation\ContactInformationGuard;
use App\Domain\Moderation\RestrictionService;
use App\Domain\Payments\PaidActionGate;
use App\Domain\Users\BlockService;
use App\Domain\Users\User;
use App\Domain\Users\UserStatus;
use Illuminate\Support\Facades\DB;

class DirectMessageService
{
    public function __construct(private readonly ContactInformationGuard $guard, private readonly BlockService $blocks, private readonly PaidActionGate $gate) {}

    public function send(User $sender, User $recipient, string $text, ?string $idempotencyKey = null): DirectMessage
    {
        return DB::transaction(function () use ($sender, $recipient, $text, $idempotencyKey) {
            app(RestrictionService::class)->authorize($sender, 'direct_messaging_disabled');
            app(RestrictionService::class)->active($recipient);
            if ($sender->is($recipient) || $sender->status !== UserStatus::Active || $recipient->status !== UserStatus::Active || $this->blocks->isBlocked($sender, $recipient)) {
                throw new \DomainException('This user is unavailable.');
            }
            $text = trim($text);
            if ($text === '' || mb_strlen($text) > (int) config('social.direct_message_max_length', 1000)) {
                throw new \DomainException('Direct message text is invalid.');
            } if ($this->guard->blocked($text)) {
                throw new \DomainException('Telegram IDs and contact links cannot be exchanged here. Use Share Telegram ID when available.');
            }
            $key = $idempotencyKey ?? bin2hex(random_bytes(16));
            $existing = DirectMessage::where('idempotency_key', $key)->first();
            if ($existing) {
                return $existing;
            }
            $this->gate->authorize('direct_message_send', $sender->id, ['recipient_id' => $recipient->id, 'reference_type' => DirectMessage::class, 'idempotency_key' => 'direct:'.$key]);

            return DirectMessage::create(['sender_user_id' => $sender->id, 'recipient_user_id' => $recipient->id, 'text' => $text, 'status' => DirectMessageStatus::Sent, 'idempotency_key' => $key]);
        });
    }

    public function markSeen(User $recipient, DirectMessage $message): DirectMessage
    {
        return $this->markSeenWithStatus($recipient, $message)[0];
    }

    public function markSeenWithStatus(User $recipient, DirectMessage $message): array
    {
        if ($message->recipient_user_id !== $recipient->id) {
            throw new \DomainException('Not your message.');
        }

        return DB::transaction(function () use ($message) {
            $m = DirectMessage::whereKey($message->id)->lockForUpdate()->firstOrFail();
            $first = false;
            if ($m->status === DirectMessageStatus::Sent) {
                $m->update(['status' => DirectMessageStatus::Seen, 'seen_at' => now()]);
                $first = true;
            }

            return [$m->fresh(), $first];
        });
    }
}
