<?php

namespace App\Domain\Discovery;

use Illuminate\Database\Eloquent\Model;

class DiscoveryHistory extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['discovered_at' => 'immutable_datetime'];
    }
}
