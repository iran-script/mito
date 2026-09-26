<?php

namespace App\Domain\Memberships;

use App\Domain\Direct\DirectMessage;
use App\Domain\Direct\DirectMessageStatus;
use App\Domain\Moderation\ContactInformationGuard;
use App\Domain\Payments\FeaturePricingService;
use App\Domain\Payments\PaidFeature;
use App\Domain\Payments\WalletService;
use App\Domain\Telegram\SocialNotificationService;
use App\Domain\Users\BlockService;
use App\Domain\Users\User;
use App\Domain\Users\UserStatus;
use Illuminate\Support\Facades\DB;

class BulkDirectMessageService
{
    public function __construct(private readonly BulkMessagingPolicy $policy, private readonly GoldLimitsService $limits, private readonly BlockService $blocks, private readonly ContactInformationGuard $guard, private readonly FeaturePricingService $pricing, private readonly WalletService $wallets) {}

    public function send(User $sender, array $recipients, string $text, string $key): array
    {
        return DB::transaction(function () use ($sender, $recipients, $text, $key) {
            $previous = DB::table('gold_usage_events')->where('idempotency_key', $key)->first();
            if ($previous) {
                return ['selected' => count($recipients), 'sent' => (int) $previous->recipient_count, 'skipped' => max(0, count($recipients) - (int) $previous->recipient_count), 'required' => 0];
            }
            $text = trim($text);
            if ($text === '' || $this->guard->blocked($text)) {
                throw new \DomainException('Telegram IDs and contact links cannot be exchanged here.');
            }$eligible = [];
            foreach ($recipients as $r) {
                if ($r->id !== $sender->id && $r->status === UserStatus::Active && ! $this->blocks->isBlocked($sender, $r)) {
                    $eligible[] = $r;
                }
            }$this->policy->authorize($sender, 'bulk_direct_message', count($eligible), $key);
            $unitCost = $this->pricing->cost(PaidFeature::DirectMessage);
            if ($unitCost === null) {
                throw new \DomainException('Direct messaging is temporarily disabled.');
            }
            $cost = $unitCost * count($eligible);
            if ($cost) {
                $this->wallets->debit($sender, $cost, 'gold_bulk_direct', ['idempotency_key' => 'gold_bulk:'.$key, 'metadata' => ['recipients' => array_map(fn ($r) => $r->id, $eligible)]]);
            }foreach ($eligible as $r) {
                $message = DirectMessage::create(['sender_user_id' => $sender->id, 'recipient_user_id' => $r->id, 'text' => $text, 'status' => DirectMessageStatus::Sent, 'idempotency_key' => 'gold_bulk:'.$key.':'.$r->id]);
                app(SocialNotificationService::class)->directMessage($r, $message);
            }if ($eligible) {
                $this->limits->record($sender, 'bulk_direct_message', count($eligible), $key);
            }

            return ['selected' => count($recipients), 'sent' => count($eligible), 'skipped' => count($recipients) - count($eligible), 'required' => $cost];
        });
    }
}
