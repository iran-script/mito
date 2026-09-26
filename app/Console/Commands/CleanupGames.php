<?php

namespace App\Console\Commands;

use App\Domain\Games\GameService;
use Illuminate\Console\Command;

class CleanupGames extends Command
{
    protected $signature = 'games:cleanup';

    protected $description = 'Expire stale game invitations and sessions';

    public function handle(GameService $games): int
    {
        $this->info('Expired games: '.$games->expireStale());

        return self::SUCCESS;
    }
}
