<?php

namespace App\Domain\Chat;

use App\Domain\Users\User;
use Illuminate\Database\Eloquent\Model;

class ChatMessage extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['message_type' => MessageType::class, 'is_protected' => 'boolean', 'sent_at' => 'immutable_datetime', 'delivered_at' => 'immutable_datetime', 'read_at' => 'immutable_datetime'];
    }

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }
}
