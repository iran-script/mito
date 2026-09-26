<?php

namespace App\Domain\Telegram\Actions;

use App\Domain\Telegram\IncomingUpdate;
use App\Domain\Telegram\Jobs\ProcessUpdate;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class AcceptUpdate
{
    public function execute(array $data): void
    {
        $data = IncomingUpdate::sanitized($data);
        $update = new IncomingUpdate($data);
        DB::transaction(function () use ($data, $update) {
            $inserted = DB::table('telegram_updates')->insertOrIgnore([
                'update_id' => $data['update_id'], 'telegram_user_id' => $update->userId(),
                'payload' => Crypt::encryptString(json_encode($data, JSON_THROW_ON_ERROR)), 'created_at' => now(), 'updated_at' => now(),
            ]);
            // Database queue insertion shares this transaction, avoiding a commit/dispatch crash gap.
            if ($inserted) {
                ProcessUpdate::dispatch($data['update_id'])->onConnection('database')->onQueue('telegram');
            }
        });
    }
}
