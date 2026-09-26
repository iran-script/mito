<?php

namespace App\Domain\Telegram;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class HttpTelegramClient implements TelegramClient
{
    public function send(string $method, array $parameters): ?array
    {
        if (! in_array($method, ['sendMessage', 'sendPhoto', 'sendVoice', 'deleteMessage', 'answerCallbackQuery'], true)) {
            throw new RuntimeException('Unsupported Telegram method.');
        }
        $token = config('telegram.token');
        if (! $token) {
            throw new RuntimeException('Telegram token is not configured.');
        }
        try {
            $response = Http::connectTimeout(5)->timeout(15)->post("https://api.telegram.org/bot{$token}/{$method}", $parameters);
        } catch (Throwable) {
            // Never propagate HTTP exceptions containing the token URL or request body.
            throw new RuntimeException('Telegram transport unavailable.');
        }
        // Expired callback acknowledgements must not block profile messages.
        if ($method === 'answerCallbackQuery' && $response->status() === 400) {
            return null;
        }
        if (! $response->successful() || $response->json('ok') !== true) {
            throw new RuntimeException('Telegram delivery failed (HTTP '.$response->status().').');
        }

        return is_array($response->json('result')) ? $response->json('result') : null;
    }
}
