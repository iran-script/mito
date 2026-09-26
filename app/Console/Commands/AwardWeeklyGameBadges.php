<?php

namespace App\Console\Commands;

use App\Domain\Games\GameProgressionService;
use Illuminate\Console\Command;

class AwardWeeklyGameBadges extends Command
{
    protected $signature = 'games:weekly-badges';

    protected $description = 'Award lifetime Top 10 Weekly badges for the previous completed application week';

    public function handle(GameProgressionService $progress): int
    {
        $this->info('Weekly badges awarded: '.$progress->awardWeekly());

        return self::SUCCESS;
    }
}
