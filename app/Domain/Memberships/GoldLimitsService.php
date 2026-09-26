<?php

namespace App\Domain\Memberships;

use App\Domain\Users\User;
use Illuminate\Support\Facades\DB;

class GoldLimitsService
{
    public function value(string $key, int $default): int
    {
        return (int) (DB::table('economy_settings')->where('key', $key)->value('value') ?? $default);
    }

    public function maxRecipients(): int
    {
        return $this->value('gold_bulk_max_recipients_per_action', 10);
    }

    public function dailyChat(User $u): int
    {
        return $this->value('gold_bulk_chat_requests_per_day', 30);
    }

    public function dailyDirect(User $u): int
    {
        return $this->value('gold_bulk_direct_messages_per_day', 20);
    }

    public function cooldown(): int
    {
        return $this->value('gold_bulk_actions_cooldown_seconds', 60);
    }

    public function can(User $u, string $action, int $count, string $key): void
    {
        $used = (int) DB::table('gold_usage_events')->where('user_id', $u->id)->where('action', $action)->where('occurred_at', '>=', now()->startOfDay())->sum('recipient_count');
        $limit = $action === 'bulk_chat_request' ? $this->dailyChat($u) : $this->dailyDirect($u);
        if ($used + $count > $limit) {
            throw new \DomainException('Gold daily limit reached.');
        }$last = DB::table('gold_usage_events')->where('user_id', $u->id)->where('occurred_at', '>=', now()->subSeconds($this->cooldown()))->exists();
        if ($last) {
            throw new \DomainException('Please wait before starting another bulk action.');
        }if ($count > $this->maxRecipients()) {
            throw new \DomainException('Too many recipients for one action.');
        }
    }

    public function record(User $u, string $action, int $count, string $key): void
    {
        DB::table('gold_usage_events')->insertOrIgnore(['user_id' => $u->id, 'action' => $action, 'recipient_count' => $count, 'idempotency_key' => $key, 'occurred_at' => now()]);
    }
}
