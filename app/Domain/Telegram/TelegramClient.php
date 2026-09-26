<?php

namespace App\Domain\Telegram;

interface TelegramClient
{
    public function send(string $method, array $parameters): ?array;
}
