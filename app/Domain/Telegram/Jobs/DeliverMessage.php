<?php

namespace App\Domain\Telegram\Jobs;

use App\Domain\Telegram\PermanentTelegramFailure;
use App\Domain\Telegram\TelegramClient;
use App\Domain\Telegram\TelegramFailure;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DeliverMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 0;

    public int $maxExceptions = 5;

    public int $timeout = 30;

    public const MAX_ATTEMPTS = 3;

    public function __construct(public int $outboxId) {}

    public function backoff(): array
    {
        return [5, 30, 120, 300];
    }

    public function handle(TelegramClient $client): void
    {
        $error = null;
        $again = false;
        DB::transaction(function () use ($client, &$error, &$again) {
            $target = DB::table('telegram_outbox')->where('id', $this->outboxId)->first();
            if (! $target || $target->sent_at || $target->failed_at) {
                return;
            }
            $sender = DB::table('telegram_updates')->where('update_id', $target->update_id)->value('telegram_user_id');
            DB::select('SELECT pg_advisory_xact_lock(?)', [-$sender]);
            // Drive the oldest pending response, even if its original queue job was lost.
            // Never defer forever behind an orphaned or terminally failed outbox row.
            $message = DB::table('telegram_outbox as o')->join('telegram_updates as u', 'u.update_id', '=', 'o.update_id')
                ->where('u.telegram_user_id', $sender)->whereNull('o.sent_at')->whereNull('o.failed_at')
                ->where('o.id', '<=', $target->id)->orderBy('o.id')->select('o.*')->lockForUpdate()->first();
            if (! $message) {
                return;
            }
            $attempt = $message->attempt_count + 1;
            try {
                $payload = json_decode(Crypt::decryptString($message->payload), true, 512, JSON_THROW_ON_ERROR);
                $client->send($payload['method'], $payload['parameters']);
                DB::table('telegram_outbox')->where('id', $message->id)->update([
                    'payload' => null, 'sent_at' => now(), 'status' => 'sent', 'attempt_count' => $attempt, 'updated_at' => now(),
                ]);
            } catch (\Throwable $e) {
                $terminal = $e instanceof PermanentTelegramFailure || $attempt >= self::MAX_ATTEMPTS;
                DB::table('telegram_outbox')->where('id', $message->id)->update([
                    'status' => $terminal ? 'failed' : 'pending', 'attempt_count' => $attempt,
                    'last_error_class' => get_class($e), 'failed_at' => $terminal ? now() : null, 'updated_at' => now(),
                ]);
                Log::error('telegram_delivery_failed', ['outbox_id' => $message->id, 'terminal' => $terminal, 'attempt' => $attempt] + TelegramFailure::context($e));
                if (! $terminal) {
                    $error = $e;
                }
            }
            $again = $message->id !== $target->id;
        });
        // Persist retry/terminal metadata BEFORE raising a transport error to the worker.
        if ($error) {
            throw $error;
        }
        if ($again) {
            $this->release(1);
        }
    }

    public function failed(?\Throwable $error): void
    {
        DB::table('telegram_outbox')->where('id', $this->outboxId)->whereNull('sent_at')->whereNull('failed_at')->update([
            'status' => 'failed', 'failed_at' => now(), 'last_error_class' => $error ? get_class($error) : 'WorkerFailure', 'updated_at' => now(),
        ]);
    }
}
