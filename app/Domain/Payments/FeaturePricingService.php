<?php

namespace App\Domain\Payments;

use Illuminate\Support\Facades\DB;

class FeaturePricingService
{
    public function cost(PaidFeature|string $feature): ?int
    {
        $code = $feature instanceof PaidFeature ? $feature->value : $feature;
        $row = DB::table('coin_feature_prices')->where('feature_code', $code)->first();
        if (! $row || ! $row->is_active) {
            return null;
        }

        return (int) $row->coin_cost;
    }
}
