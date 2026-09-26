<?php

namespace App\Domain\Memberships;

use App\Domain\Moderation\RestrictionService;
use App\Domain\Users\User;

class BulkMessagingPolicy
{
    public function __construct(private readonly GoldMembershipService $memberships, private readonly GoldLimitsService $limits) {}

    public function authorize(User $user, string $action, int $count, string $key): void
    {
        app(RestrictionService::class)->authorize($user, 'bulk_messaging_disabled');
        app(RestrictionService::class)->authorize($user, $action === 'bulk_direct_message' ? 'direct_messaging_disabled' : 'chat_requests_disabled');
        if (! $this->memberships->isGold($user)) {
            throw new \DomainException('Gold membership is required.');
        }$this->limits->can($user, $action, $count, $key);
    }
}
