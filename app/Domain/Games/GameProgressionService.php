<?php

namespace App\Domain\Games;

use App\Domain\Users\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class GameProgressionService
{
    public function stats(User $user): array
    {
        $s = app(GameService::class)->stats($user);
        $competitive = $s->wins + $s->losses + $s->draws;
        $perGame = Cache::remember('games:breakdown:'.$user->id.':'.$s->games_played, 3600, fn () => DB::table('game_participants as p')->join('game_sessions as s', 's.id', '=', 'p.game_session_id')->where('p.user_id', $user->id)->where('s.status', 'completed')->selectRaw('s.game_type, COUNT(*) as played')->groupBy('s.game_type')->pluck('played', 'game_type')->all());

        return (array) $s + ['win_rate' => $competitive ? round(100 * $s->wins / $competitive, 1) : 0, 'rank' => app(RankingService::class)->rank($s->competitive_score), 'per_game' => $perGame];
    }

    public function award(User $user): void
    {
        $s = app(GameService::class)->stats($user);
        $codes = [];
        if ($s->wins >= 1) {
            $codes[] = 'first_win';
        }
        if ($s->best_streak >= 5) {
            $codes[] = 'five_streak';
        }
        if ($s->wins >= 50) {
            $codes[] = 'fifty_wins';
        }
        if ($s->games_played >= 100) {
            $codes[] = 'hundred_games';
        }
        if (GameSession::where('status', 'completed')->where('game_type', GameType::SpeedQuiz)->whereHas('participants', fn ($q) => $q->where('users.id', $user->id))->count() >= 10) {
            $codes[] = 'quiz_master';
        }
        if (GameSession::where('status', 'completed')->where('game_type', GameType::RockPaperScissors)->where('state->winner_user_id', $user->id)->count() >= 10) {
            $codes[] = 'rps_champion';
        }
        foreach (Badge::whereIn('code', $codes)->get() as $badge) {
            DB::table('user_badges')->insertOrIgnore(['user_id' => $user->id, 'badge_id' => $badge->id, 'awarded_at' => now()]);
        }
    }

    public function awardWeekly(): int
    {
        $badge = Badge::where('code', 'top_ten_weekly')->first();
        if (! $badge) {
            return 0;
        }
        $rankings = app(RankingService::class);
        [$start, $end] = $rankings->bounds('weekly', CarbonImmutable::now(config('app.timezone'))->subWeek());
        $leaders = DB::query()->fromSub($rankings->ratingTotals($start, $end), 't')->join('users as u', 'u.id', '=', 't.user_id')->join('profiles as p', 'p.user_id', '=', 'u.id')
            ->where('u.status', 'active')->where('p.status', 'active')->where('t.score', '>', 0)->orderByDesc('t.score')->orderBy('u.id')->limit(10)->pluck('u.id');
        $awarded = 0;
        foreach ($leaders as $id) {
            $awarded += DB::table('user_badges')->insertOrIgnore(['user_id' => $id, 'badge_id' => $badge->id, 'awarded_at' => now()]);
        }

        return $awarded;
    }

    public function badges(User $user): array
    {
        $icons = ['top_ten_weekly' => '🌟', 'first_win' => '🏅', 'five_streak' => '🔥', 'fifty_wins' => '🏆', 'hundred_games' => '🎮', 'quiz_master' => '🧠', 'rps_champion' => '✊'];

        return DB::table('user_badges as earned')->join('badges as b', 'b.id', '=', 'earned.badge_id')->where('earned.user_id', $user->id)->orderBy('earned.awarded_at')->get(['b.code', 'b.name', 'b.description', 'earned.awarded_at'])->map(fn ($b) => ['icon' => $icons[$b->code] ?? '🎖', 'name' => $b->name, 'description' => $b->description, 'earned_date' => CarbonImmutable::parse($b->awarded_at)->setTimezone(app()->getLocale() === 'fa' ? config('presentation.timezone') : config('app.timezone'))->toDateString()])->all();
    }
}
