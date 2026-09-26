<?php

namespace App\Domain\Chat;

use App\Domain\Users\User;
use Illuminate\Database\Eloquent\Model;

class ChatRequest extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['status' => ChatRequestStatus::class, 'seen_at' => 'immutable_datetime', 'accepted_at' => 'immutable_datetime', 'rejected_at' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime'];
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requester_user_id');
    }

    public function recipient()
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }
}
