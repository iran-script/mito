<?php

namespace App\Domain\Telegram\Jobs;

use App\Domain\Telegram\TelegramClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class DeliverMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 0;

    public int $maxExceptions = 5;

    public int $timeout = 30;

    public function __construct(public int $outboxId) {}

    public function backoff(): array
    {
        return [5, 30, 120, 300];
    }

    public function handle(TelegramClient $client): void
    {
        DB::transaction(function () use ($client) {
            $message = DB::table('telegram_outbox')->where('id', $this->outboxId)->lockForUpdate()->first();
            if (! $message || $message->sent_at) {
                return;
            }
            $sender = DB::table('telegram_updates')->where('update_id', $message->update_id)->value('telegram_user_id');
            // Separate lock namespace from incoming processing; maintain per-user delivery order.
            DB::select('SELECT pg_advisory_xact_lock(?)', [-$sender]);
            $earlier = DB::table('telegram_outbox as o')->join('telegram_updates as u', 'u.update_id', '=', 'o.update_id')
                ->where('u.telegram_user_id', $sender)->whereNull('o.sent_at')->where('o.id', '<', $message->id)->exists();
            if ($earlier) {
                $this->release(2);

                return;
            }
            $payload = json_decode(Crypt::decryptString($message->payload), true, 512, JSON_THROW_ON_ERROR);
            $client->send($payload['method'], $payload['parameters']);
            DB::table('telegram_outbox')->where('id', $this->outboxId)->update(['payload' => null, 'sent_at' => now(), 'updated_at' => now()]);
        });
    }
}
