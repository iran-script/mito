<?php

namespace App\Domain\Payments;

use Illuminate\Database\Eloquent\Model;

class CoinPackage extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['base_coins' => 'integer', 'bonus_coins' => 'integer', 'price_amount' => 'integer', 'is_active' => 'boolean', 'is_featured' => 'boolean', 'starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime'];
    }

    public function available(): bool
    {
        return $this->is_active && (! $this->starts_at || $this->starts_at->isPast()) && (! $this->ends_at || $this->ends_at->isFuture());
    }
}
