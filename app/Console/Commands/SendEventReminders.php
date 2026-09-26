<?php

namespace App\Console\Commands;

use App\Domain\Events\EventReminderService;
use Illuminate\Console\Command;

class SendEventReminders extends Command
{
    protected $signature = 'events:send-reminders';

    protected $description = 'Queue due event reminders';

    public function handle(EventReminderService $reminders): int
    {
        $this->info('Queued reminders: '.$reminders->queueDue());

        return self::SUCCESS;
    }
}
