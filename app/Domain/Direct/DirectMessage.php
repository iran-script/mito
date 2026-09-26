<?php

namespace App\Domain\Direct;

use App\Domain\Users\User;
use Illuminate\Database\Eloquent\Model;

class DirectMessage extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['status' => DirectMessageStatus::class, 'seen_at' => 'immutable_datetime'];
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    public function recipient()
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }
}
