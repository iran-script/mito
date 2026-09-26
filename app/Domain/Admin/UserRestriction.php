<?php

namespace App\Domain\Admin;

use Illuminate\Database\Eloquent\Model;

class UserRestriction extends Model
{
    protected $table = 'user_restrictions';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime'];
    }
}
