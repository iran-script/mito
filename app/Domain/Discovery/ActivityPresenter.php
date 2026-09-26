<?php

namespace App\Domain\Discovery;

use Carbon\CarbonInterface;

final class ActivityPresenter
{
    public static function label(?CarbonInterface $at): string
    {
        if (! $at) {
            return __('activity unavailable');
        }
        $minutes = max(0, (int) $at->diffInMinutes(now()));
        if ($minutes < 2) {
            return __('active now');
        }
        if ($minutes < 60) {
            return __('active :v1 minutes ago', ['v1' => $minutes]);
        }
        $hours = (int) floor($minutes / 60);
        if ($hours < 24) {
            return __('active :v1 hours ago', ['v1' => $hours]);
        }

        return __('active ').(int) floor($hours / 24).__(' days ago');
    }
}
