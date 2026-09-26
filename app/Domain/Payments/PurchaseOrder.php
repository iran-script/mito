<?php

namespace App\Domain\Payments;

use App\Domain\Users\User;
use Illuminate\Database\Eloquent\Model;

class PurchaseOrder extends Model
{
    protected $table = 'coin_purchase_orders';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount_snapshot' => 'integer', 'base_coins_snapshot' => 'integer', 'bonus_coins_snapshot' => 'integer', 'paid_at' => 'immutable_datetime'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
