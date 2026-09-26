<?php

namespace App\Domain\Memberships;

use App\Domain\Users\User;

class GoldMembershipService
{
    public function active(User|int $user): ?Membership
    {
        $id = $user instanceof User ? $user->id : $user;

        return Membership::where('user_id', $id)->where('plan_code', 'gold')->where('status', 'active')->where('starts_at', '<=', now())->where('ends_at', '>', now())->latest('ends_at')->first();
    }

    public function isGold(User|int $user): bool
    {
        return $this->active($user) !== null;
    }

    public function activate(User $user, \DateTimeInterface $endsAt, string $source = 'admin'): Membership
    {
        return Membership::create(['user_id' => $user->id, 'plan_code' => 'gold', 'starts_at' => now(), 'ends_at' => $endsAt, 'status' => MembershipStatus::Active, 'source' => $source]);
    }
}
