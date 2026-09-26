<?php

namespace App\Domain\Games;

use App\Domain\Users\User;
use Illuminate\Database\Eloquent\Model;

class GameSession extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['game_type' => GameType::class, 'status' => GameStatus::class, 'state' => 'array', 'expires_at' => 'immutable_datetime'];
    }

    public function participants()
    {
        return $this->belongsToMany(User::class, 'game_participants')->withPivot(['score', 'xp_earned'])->withTimestamps();
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
