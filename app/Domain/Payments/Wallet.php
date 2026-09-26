<?php

namespace App\Domain\Payments;

use App\Domain\Users\User;
use Illuminate\Database\Eloquent\Model;

class Wallet extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['balance' => 'integer'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function transactions()
    {
        return $this->hasMany(CoinTransaction::class);
    }
}
