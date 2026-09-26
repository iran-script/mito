<?php

namespace App\Domain\Games;

use Illuminate\Support\Facades\DB;

class GameAvailability
{
    public function assertEnabled(GameType|string $type): void
    {
        $key = $type instanceof GameType ? $type->value : $type;
        if (DB::table('economy_settings')->where('key', 'game_enabled_'.$key)->value('value') === '0') {
            throw new \DomainException('This game is temporarily unavailable.');
        }
    }
}
