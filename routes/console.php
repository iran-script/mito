<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Requires a production schedule:run runner; registration alone does not deploy it.
Schedule::command('events:send-reminders')->everyMinute()->withoutOverlapping();
Schedule::command('games:cleanup')->everyMinute()->withoutOverlapping();
Schedule::command('chat:expire-requests')->everyMinute()->withoutOverlapping();

Schedule::command('games:weekly-badges')->weeklyOn(1, '00:05')->timezone(config('app.timezone'))->withoutOverlapping();
