<?php

namespace App\Domain\Games;

class SpeedQuizScoringService
{
    public function score(bool $correct, int $responseMs): int
    {
        if (! $correct) {
            return 0;
        }
        $base = (int) config('speed_quiz.base_score', 100);
        $max = (int) config('speed_quiz.speed_bonus_max', 50);
        $window = max(1, (int) config('speed_quiz.speed_bonus_window_ms', 15000));
        $bonus = max(0, (int) round($max * (($window - min($window, max(0, $responseMs))) / $window)));

        return $base + $bonus;
    }
}
