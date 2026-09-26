<?php

namespace App\Domain\Telegram;

use App\Domain\Chat\Conversation;
use App\Domain\Users\User;
use Illuminate\Database\Eloquent\Model;

class InteractionState extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['mode' => 'menu', 'revision' => 0];

    protected function casts(): array
    {
        return ['bulk_selection' => 'array', 'bulk_context' => 'array', 'event_context' => 'array', 'game_context' => 'array', 'direct_context' => 'array'];
    }

    public function resetNavigation(): void
    {
        $this->update([
            'mode' => 'menu', 'conversation_id' => null, 'direct_recipient_id' => null,
            'bulk_mode' => null, 'bulk_selection' => null, 'bulk_context' => null,
            'event_context' => null, 'game_context' => null,
            'direct_context' => null,
        ]);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }

    public function directRecipient()
    {
        return $this->belongsTo(User::class, 'direct_recipient_id');
    }
}
