<?php

namespace App\Domain\Memberships;

use App\Domain\Chat\ChatRequestService;
use App\Domain\Telegram\SocialNotificationService;
use App\Domain\Users\User;
use Illuminate\Support\Facades\DB;

class BulkChatRequestService
{
    public function __construct(private readonly BulkMessagingPolicy $policy, private readonly ChatRequestService $requests, private readonly GoldLimitsService $limits) {}

    public function send(User $sender, array $recipients, string $key): array
    {
        $previous = DB::table('gold_usage_events')->where('idempotency_key', $key)->first();
        if ($previous) {
            return ['selected' => count($recipients), 'sent' => (int) $previous->recipient_count, 'skipped' => max(0, count($recipients) - (int) $previous->recipient_count)];
        }
        $ids = array_values(array_unique(array_map(fn ($u) => (int) $u->id, $recipients)));
        $this->policy->authorize($sender, 'bulk_chat_request', count($ids), $key);
        $sent = 0;
        $skipped = 0;
        foreach ($recipients as $recipient) {
            try {
                $request = $this->requests->create($sender, $recipient);
                app(SocialNotificationService::class)->request($recipient, $request->id);
                $sent++;
            } catch (\Throwable) {
                $skipped++;
            }
        }if ($sent) {
            $this->limits->record($sender, 'bulk_chat_request', $sent, $key);
        }

        return ['selected' => count($ids), 'sent' => $sent, 'skipped' => $skipped];
    }
}
