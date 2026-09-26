<?php

namespace App\Domain\Discovery;

use App\Domain\Profiles\Profile;
use App\Domain\Profiles\PublicProfile;

final readonly class DiscoveryResult
{
    public function __construct(public Profile $profile, public ?float $distanceKm = null) {}

    public function publicProfile(): PublicProfile
    {
        return PublicProfile::fromProfile($this->profile, $this->distanceKm);
    }
}
