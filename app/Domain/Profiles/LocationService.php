<?php

namespace App\Domain\Profiles;

use App\Domain\Users\User;

class LocationService
{
    public function update(User $user, float $latitude, float $longitude): Profile
    {
        $profile = $user->profile()->firstOrCreate();
        $profile->forceFill(['latitude' => $latitude, 'longitude' => $longitude, 'location_updated_at' => now()])->save();

        return $profile;
    }
}
