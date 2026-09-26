<?php

namespace App\Domain\Chat;

use Illuminate\Database\Eloquent\Model;

class ConversationTelegramMessage extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['deleted_at' => 'immutable_datetime'];
    }
}
