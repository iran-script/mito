<?php

namespace App\Domain\Payments;

use App\Domain\Chat\Conversation;
use App\Domain\Users\User;
use Illuminate\Database\Eloquent\Model;

class TelegramIdentityShareRequest extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['status' => IdentityShareStatus::class, 'expires_at' => 'immutable_datetime', 'accepted_at' => 'immutable_datetime', 'rejected_at' => 'immutable_datetime'];
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requester_user_id');
    }

    public function recipient()
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }
}
