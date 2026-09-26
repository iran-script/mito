<?php

namespace App\Domain\Games;

use App\Domain\Users\User;
use App\Domain\Users\UserStatus;
use Illuminate\Support\Facades\DB;

class DailyChallengeService
{
    public function question(User $user): array
    {
        app(GameAvailability::class)->assertEnabled('daily_challenge');
        if ($user->fresh()->status !== UserStatus::Active) {
            throw new \DomainException('Account unavailable.');
        }
        $date = now(config('app.timezone'))->toDateString();
        $row = DB::table('daily_game_questions')->where('challenge_date', $date)->first();
        if (! $row) {
            $q = DB::table('quiz_questions')->where('is_active', true)->where(fn ($q) => $q->whereNull('category')->orWhere('category', '!=', 'this_or_that'))->inRandomOrder()->first();
            if (! $q) {
                throw new \DomainException('No challenge is available today.');
            }
            $options = collect(json_decode($q->options, true))->shuffle()->values()->all();
            DB::table('daily_game_questions')->insertOrIgnore(['challenge_date' => $date, 'prompt' => json_encode(['question' => $q->question, 'options' => $options, 'answer' => $q->correct_option]), 'created_at' => now(), 'updated_at' => now()]);
            $row = DB::table('daily_game_questions')->where('challenge_date', $date)->first();
        }
        $p = json_decode($row->prompt, true);

        return ['date' => $date, 'question' => $p['question'], 'options' => $p['options'], 'answered' => DB::table('daily_game_answers')->where(['user_id' => $user->id, 'challenge_date' => $date])->exists()];
    }

    public function answer(User $user, string $date, int $index): array
    {
        app(GameAvailability::class)->assertEnabled('daily_challenge');

        return DB::transaction(function () use ($user, $date, $index) {
            $u = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($u->status !== UserStatus::Active || $date !== now(config('app.timezone'))->toDateString()) {
                throw new \DomainException('This challenge is unavailable.');
            }
            $row = DB::table('daily_game_questions')->where('challenge_date', $date)->first();
            if (! $row) {
                throw new \DomainException('Open today\'s challenge first.');
            }
            $p = json_decode($row->prompt, true);
            if (! array_key_exists($index, $p['options'])) {
                throw new \DomainException('Invalid answer.');
            }
            $old = DB::table('daily_game_answers')->where(['user_id' => $u->id, 'challenge_date' => $date])->first();
            if ($old) {
                return ['duplicate' => true, 'correct' => (bool) $old->is_correct, 'xp' => 0];
            }
            $correct = $p['options'][$index] === $p['answer'];
            DB::table('daily_game_answers')->insert(['user_id' => $u->id, 'challenge_date' => $date, 'option_index' => $index, 'is_correct' => $correct, 'created_at' => now(), 'updated_at' => now()]);
            $reward = app(GameService::class)->completeDailyChallenge($u);

            return ['duplicate' => false, 'correct' => $correct, 'xp' => $reward ? 10 : 0];
        });
    }
}
