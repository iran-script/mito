<?php

namespace App\Domain\Profiles;

use App\Domain\Users\User;
use App\Domain\Users\UserStatus;

class ProfilePolicy
{
    public function update(User $user, Profile $profile): bool
    {
        return $user->status === UserStatus::Active && $profile->user_id === $user->id;
    }
}
