<?php

namespace App\Domain\Users;

use Illuminate\Database\Eloquent\Model;

class UserBlock extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'user_blocks';

    protected $guarded = [];
}
