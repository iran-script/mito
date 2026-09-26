<?php

namespace App\Domain\Moderation;

use App\Domain\Users\User;
use App\Domain\Users\UserStatus;
use Illuminate\Support\Facades\DB;

class RestrictionService
{
    const TYPES = ['bulk_messaging_disabled', 'direct_messaging_disabled', 'chat_requests_disabled', 'game_invites_disabled', 'event_creation_disabled'];

    public function active(User $user): void
    {
        if (! User::whereKey($user->id)->where('status', UserStatus::Active)->exists()) {
            throw new \DomainException('Your account is unavailable.');
        }
    }

    public function authorize(User $user, ?string $type = null): void
    {
        $this->active($user);
        if ($type && DB::table('user_restrictions')->where('user_id', $user->id)->where('restriction_type', $type)->where('starts_at', '<=', now())->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))->exists()) {
            throw new \DomainException('This action is temporarily restricted.');
        }
    }
}
