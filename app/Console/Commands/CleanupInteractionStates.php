<?php

namespace App\Console\Commands;

use App\Domain\Telegram\InteractionStateResolver;
use Illuminate\Console\Command;

class CleanupInteractionStates extends Command
{
    protected $signature = 'mito:cleanup-states';

    protected $description = 'Recover expired and impossible Telegram interaction states';

    public function handle(InteractionStateResolver $resolver): int
    {
        $this->info('Recovered '.$resolver->cleanupStaleStates().' interaction states.');

        return self::SUCCESS;
    }
}
