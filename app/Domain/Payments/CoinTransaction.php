<?php

namespace App\Domain\Payments;

use Illuminate\Database\Eloquent\Model;

class CoinTransaction extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['type' => CoinTransactionType::class, 'amount' => 'integer', 'balance_before' => 'integer', 'balance_after' => 'integer', 'metadata' => 'array', 'created_at' => 'immutable_datetime'];
    }

    public function wallet()
    {
        return $this->belongsTo(Wallet::class);
    }
}
