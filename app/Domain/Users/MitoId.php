<?php

namespace App\Domain\Users;

use Illuminate\Support\Str;

final class MitoId
{
    public const PREFIX = 'm_';

    public static function generate(): string
    {
        return self::PREFIX.Str::lower(Str::random(8));
    }

    public static function normalize(?string $value): ?string
    {
        $value = Str::lower(trim((string) $value));
        $value = Str::startsWith($value, '/') ? Str::substr($value, 1) : $value;

        return preg_match('/^m_[a-z0-9]{8}$/D', $value) ? $value : null;
    }

    public static function looksLike(?string $value): bool
    {
        return preg_match('/^\/?m_/i', trim((string) $value)) === 1;
    }

    public static function display(string $value): string
    {
        return '/'.self::normalize($value);
    }
}
