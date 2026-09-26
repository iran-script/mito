<?php

namespace App\Console\Commands;

use App\Domain\Chat\ChatRequestService;
use Illuminate\Console\Command;

class ExpireChatRequests extends Command
{
    protected $signature = 'chat:expire-requests';

    protected $description = 'Expire pending or seen chat requests whose two-minute validity has ended';

    public function handle(ChatRequestService $requests): int
    {
        $count = $requests->expire();
        $this->info("Expired {$count} chat request(s).");

        return self::SUCCESS;
    }
}
