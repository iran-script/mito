<?php

namespace App\Domain\Profiles;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Province extends Model
{
    protected $guarded = ['id'];

    public function cities(): HasMany
    {
        return $this->hasMany(City::class);
    }
}
