<?php

namespace App\Domain\Events;

use App\Domain\Telegram\SocialNotificationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class EventReminderService
{
    public function __construct(private readonly SocialNotificationService $notifications) {}

    public function queueDue(?Carbon $now = null): int
    {
        $now ??= now();
        $count = 0;
        foreach (Event::with('participants')->where('status', EventStatus::Published)->where('starts_at', '>', $now)->where('starts_at', '<=', $now->copy()->addDay())->get() as $event) {
            $minutes = $now->diffInMinutes($event->starts_at, false);
            $type = $minutes <= 60 ? '1h' : ($minutes <= 1440 ? '24h' : null);
            if (! $type) {
                continue;
            }
            foreach ($event->participants()->wherePivot('status', 'joined')->get() as $participant) {
                $inserted = DB::table('event_reminders')->insertOrIgnore(['event_id' => $event->id, 'user_id' => $participant->id, 'reminder_type' => $type, 'sent_at' => $now]);
                if ($inserted) {
                    $count++;
                    $this->notifications->queue($participant, 'event_reminder', $event->id, __('Reminder: :v1 starts soon.', ['v1' => $event->title]), "event_reminder:{$event->id}:{$participant->id}:{$type}");
                }
            }
        }

        return $count;
    }
}
