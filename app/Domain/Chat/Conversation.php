<?php

namespace App\Domain\Chat;

use App\Domain\Users\User;
use Illuminate\Database\Eloquent\Model;

class Conversation extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => ConversationStatus::class,
            'is_protected' => 'boolean',
            'activated_at' => 'immutable_datetime',
            'engagement_rewarded_at' => 'immutable_datetime',
            'telegram_cleanup_requested_at' => 'immutable_datetime',
            'telegram_cleanup_completed_at' => 'immutable_datetime',
        ];
    }

    public function participants()
    {
        return $this->belongsToMany(User::class, 'conversation_participants')->withPivot('last_read_at');
    }

    public function messages()
    {
        return $this->hasMany(ChatMessage::class);
    }
}
