<?php

namespace App\Domain\Admin;

use Illuminate\Database\Eloquent\Model;

class FeaturePrice extends Model
{
    protected $table = 'coin_feature_prices';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'coin_cost' => 'integer'];
    }
}
