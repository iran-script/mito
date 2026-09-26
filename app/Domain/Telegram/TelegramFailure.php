<?php

namespace App\Domain\Telegram;

final class TelegramFailure
{
    public static function context(\Throwable $error): array
    {
        preg_match('/constraint "([a-z0-9_]+)"/', $error->getMessage(), $constraint);

        return ['error_class' => get_class($error), 'error_code' => (string) $error->getCode(),
            'constraint' => $constraint[1] ?? null,
            'trace' => array_map(fn ($frame) => array_intersect_key($frame, array_flip(['file', 'line', 'class', 'function'])), array_slice($error->getTrace(), 0, 12))];
    }
}
