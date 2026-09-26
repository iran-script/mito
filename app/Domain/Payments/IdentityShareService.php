<?php

namespace App\Domain\Payments;

use App\Domain\Chat\Conversation;
use App\Domain\Chat\ConversationStatus;
use App\Domain\Moderation\RestrictionService;
use App\Domain\Users\BlockService;
use App\Domain\Users\User;
use App\Domain\Users\UserStatus;
use Illuminate\Support\Facades\DB;

class IdentityShareService
{
    public function __construct(private readonly BlockService $blocks, private readonly PaidActionGate $gate, private readonly WalletService $wallets) {}

    public function request(User $requester, User $recipient, ?int $conversationId = null): TelegramIdentityShareRequest
    {
        app(RestrictionService::class)->active($requester);
        app(RestrictionService::class)->active($recipient);
        if ($requester->is($recipient) || $requester->status !== UserStatus::Active || $recipient->status !== UserStatus::Active || $this->blocks->isBlocked($requester, $recipient)) {
            throw new \DomainException('This user is unavailable.');
        }

        return TelegramIdentityShareRequest::firstOrCreate(['requester_user_id' => $requester->id, 'recipient_user_id' => $recipient->id, 'status' => IdentityShareStatus::Pending], ['conversation_id' => $conversationId, 'expires_at' => now()->addHours((int) config('social.identity_share_expiry_hours', 72))]);
    }

    public function accept(User $recipient, TelegramIdentityShareRequest $request): array
    {
        return DB::transaction(function () use ($recipient, $request) {
            $r = TelegramIdentityShareRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            app(RestrictionService::class)->active($recipient);
            app(RestrictionService::class)->active($r->requester);
            if ($this->blocks->isBlocked($r->requester, $recipient)) {
                throw new \DomainException('This user is unavailable.');
            }
            if ($r->recipient_user_id !== $recipient->id) {
                throw new \DomainException('Only the recipient can accept.');
            }
            if ($r->expires_at?->isPast()) {
                $r->update(['status' => IdentityShareStatus::Expired]);
                throw new \DomainException('This request expired.');
            }
            if ($r->status === IdentityShareStatus::Accepted) {
                return [$r, $r->requester->telegram_username, $r->recipient->telegram_username];
            }
            if ($r->status !== IdentityShareStatus::Pending) {
                throw new \DomainException('This request is no longer available.');
            }
            if ($r->conversation_id) {
                $conversation = Conversation::whereKey($r->conversation_id)->first();
                if (! $conversation || $conversation->status !== ConversationStatus::Active || $this->blocks->isBlocked($r->requester, $recipient)) {
                    throw new \DomainException('This conversation is unavailable.');
                }
            }
            $requester = $r->requester;
            if (! $requester->telegram_username || ! $recipient->telegram_username) {
                throw new \DomainException('Telegram username sharing is unavailable for one participant.');
            }
            $this->wallets->wallet($requester);
            $this->gate->authorize('telegram_id_share', $requester->id, ['reference_type' => self::class, 'reference_id' => $r->id, 'idempotency_key' => 'telegram_share:'.$r->id]);
            $r->update(['status' => IdentityShareStatus::Accepted, 'accepted_at' => now()]);
            $tx = CoinTransaction::where('idempotency_key', 'telegram_share:'.$r->id)->firstOrFail();
            DB::table('telegram_identity_share_events')->insert(['share_request_id' => $r->id, 'requester_user_id' => $requester->id, 'recipient_user_id' => $recipient->id, 'coin_transaction_id' => $tx->id, 'requester_username_snapshot' => $requester->telegram_username, 'recipient_username_snapshot' => $recipient->telegram_username, 'created_at' => now(), 'updated_at' => now()]);

            return [$r->fresh(), $requester->telegram_username, $recipient->telegram_username];
        });
    }
}
