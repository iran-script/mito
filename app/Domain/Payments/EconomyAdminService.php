<?php

namespace App\Domain\Payments;

use Illuminate\Support\Facades\DB;

/** Application seam for a future authenticated Filament resource. */
class EconomyAdminService
{
    public function setPrice(PaidFeature $feature, int $cost, bool $active = true): void
    {
        if ($cost < 0) {
            throw new \DomainException('Coin cost cannot be negative.');
        }
        DB::table('coin_feature_prices')->updateOrInsert(['feature_code' => $feature->value], ['coin_cost' => $cost, 'is_active' => $active, 'updated_at' => now(), 'created_at' => now()]);
    }

    public function setSignupBonus(int $coins): void
    {
        if ($coins < 0) {
            throw new \DomainException('Signup bonus cannot be negative.');
        }
        DB::table('economy_settings')->updateOrInsert(['key' => 'signup_bonus'], ['value' => (string) $coins, 'updated_at' => now(), 'created_at' => now()]);
    }
}
