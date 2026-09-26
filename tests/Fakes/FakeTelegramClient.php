<?php

namespace Tests\Fakes;

use App\Domain\Telegram\TelegramClient;

class FakeTelegramClient implements TelegramClient
{
    public array $sent = [];

    public array $failDeleteMessageIds = [];

    private int $nextMessageId = 1000;

    public function send(string $method, array $parameters): ?array
    {
        $this->sent[] = compact('method', 'parameters');
        if ($method === 'deleteMessage' && in_array($parameters['message_id'] ?? null, $this->failDeleteMessageIds, true)) {
            throw new \RuntimeException('Telegram deletion failed.');
        }

        return str_starts_with($method, 'send') ? ['message_id' => $this->nextMessageId++] : null;
    }
}
