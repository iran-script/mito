<?php

namespace App\Domain\Discovery;

enum DistanceRange: string
{
    case ZeroToFive = '05';
    case FiveToTen = '510';
    case TenToFifteen = '1015';
    case FifteenToTwenty = '1520';
    case ZeroToTwenty = '020';

    public function bounds(): array
    {
        return match ($this) {
            self::ZeroToFive => [0, 5], self::FiveToTen => [5, 10],
            self::TenToFifteen => [10, 15], self::FifteenToTwenty => [15, 20],
            self::ZeroToTwenty => [0, 20],
        };
    }
}
