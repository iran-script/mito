<?php

namespace App\Support;

use BackedEnum;
use Carbon\CarbonImmutable;
use DateTimeInterface;

final class Presentation
{
    public static function label(mixed $value): string
    {
        $value = $value instanceof BackedEnum ? $value->value : (string) $value;
        if (app()->getLocale() === 'fa') {
            $labels = trans('presentation');
            if (is_array($labels) && isset($labels[$value])) {
                return $labels[$value];
            }
            $code = strtolower(str_replace(' ', '_', $value));
            if (is_array($labels) && isset($labels[$code])) {
                return $labels[$code];
            }
        }

        return __($value);
    }

    public static function options(array $values): array
    {
        return array_map(self::label(...), $values);
    }

    public static function date(DateTimeInterface|string|null $value): string
    {
        return $value === null ? __('Not specified') : CarbonImmutable::parse($value)->setTimezone(config('presentation.timezone'))->format(config('presentation.date_format')).' '.__('(Gregorian)');
    }

    /** Recognize translated navigation while retaining old English text shortcuts. */
    public static function asciiDigits(string $value): string
    {
        return strtr($value, array_combine(preg_split('//u', '۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩', -1, PREG_SPLIT_NO_EMPTY), str_split('01234567890123456789')));
    }

    public static function persianDigits(string|int $value): string
    {
        return strtr((string) $value, array_combine(str_split('0123456789'), preg_split('//u', '۰۱۲۳۴۵۶۷۸۹', -1, PREG_SPLIT_NO_EMPTY)));
    }

    public static function input(string $text): string
    {
        foreach (['Back', 'Chats', 'Wallet / Coins', 'Search People', 'Anonymous Search', 'Nearby'] as $label) {
            if ($text === __($label)) {
                return $label;
            }
        }

        return $text;
    }

    public static function error(string $message): string
    {
        if (preg_match('/^This action requires ([0-9]+) coins\. Your balance is ([0-9]+)\.$/', $message, $m)) {
            return __('This action requires :required coins. Your balance is :balance.', ['required' => $m[1], 'balance' => $m[2]]);
        }
        if (preg_match('/^Choose a number from ([0-9]+) to ([0-9]+)\.$/', $message, $m)) {
            return __('Choose a number from :min to :max.', ['min' => $m[1], 'max' => $m[2]]);
        }
        $translated = __($message);
        if (app()->getLocale() === 'fa' && $translated === $message && preg_match('/[A-Za-z]/', $message)) {
            return __('This action is unavailable. Please try again.');
        }

        return $translated;
    }
}
