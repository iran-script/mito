<?php

namespace App\Domain\Profiles;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class City extends Model
{
    protected $guarded = ['id'];

    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }
}
