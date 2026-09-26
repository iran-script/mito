<?php

namespace App\Domain\Games;

use App\Domain\Moderation\ContactInformationGuard;
use App\Domain\Users\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class RankingService
{
    public function rank(int $rating): array
    {
        $tiers = config('ranking.tiers');
        ksort($tiers, SORT_NUMERIC);
        $current = array_key_first($tiers);
        $next = null;
        foreach ($tiers as $threshold => $name) {
            if ($rating >= $threshold) {
                $current = $threshold;
            } else {
                $next = $threshold;
                break;
            }
        }

        return ['tier' => $tiers[$current], 'rating' => $rating, 'next_tier' => $next === null ? null : $tiers[$next], 'next_threshold' => $next,
            'remaining' => $next === null ? 0 : max(0, $next - $rating),
            'progress' => $next === null ? 100 : (int) floor(100 * max(0, $rating - $current) / ($next - $current))];
    }

    public function bounds(string $period, ?CarbonImmutable $at = null): array
    {
        $at ??= CarbonImmutable::now(config('app.timezone'));
        $start = match ($period) {
            'daily' => $at->startOfDay(),
            'weekly' => $at->startOfWeek(CarbonImmutable::MONDAY),
            'monthly' => $at->startOfMonth(),
            default => throw new \DomainException('Unknown leaderboard period.'),
        };
        $end = match ($period) {
            'daily' => $start->addDay(), 'weekly' => $start->addWeek(), 'monthly' => $start->addMonth()
        };

        return [$start->utc()->toIso8601String(), $end->utc()->toIso8601String()];
    }

    public function leaderboard(?User $viewer, string $period = 'all', int $page = 1): array
    {
        if (! in_array($period, ['all', 'daily', 'weekly', 'monthly', 'city', 'contacts'], true)) {
            throw new \DomainException('Unknown leaderboard.');
        }
        $q = DB::table('game_player_stats as st')->join('users as u', 'u.id', '=', 'st.user_id')->join('profiles as p', 'p.user_id', '=', 'u.id')
            ->where('u.status', 'active')->where('p.status', 'active');
        // Hide blocked peers in both directions; only display names leave this service.
        if ($viewer) {
            $q->whereNotExists(fn ($b) => $b->selectRaw('1')->from('user_blocks as b')->where(fn ($pairs) => $pairs
                ->where(fn ($x) => $x->where('b.blocker_user_id', $viewer->id)->whereColumn('b.blocked_user_id', 'u.id'))
                ->orWhere(fn ($x) => $x->where('b.blocked_user_id', $viewer->id)->whereColumn('b.blocker_user_id', 'u.id'))));
        }
        if ($period === 'city') {
            $q->where('p.city_id', $viewer?->fresh()?->profile?->city_id ?? -1);
        }
        if ($period === 'contacts') {
            $q->whereIn('u.id', DB::table('user_contacts')->where('user_id', $viewer?->id ?? -1)->select('contact_user_id'));
        }
        $score = 'st.competitive_score';
        if (in_array($period, ['daily', 'weekly', 'monthly'], true)) {
            [$start, $end] = $this->bounds($period);
            $totals = $this->ratingTotals($start, $end);
            $q->leftJoinSub($totals, 't', 't.user_id', '=', 'u.id');
            $score = 'COALESCE(t.score, 0)';
        }
        $ranked = $q->selectRaw("u.id as user_id, p.display_name, u.telegram_username, u.telegram_user_id, st.competitive_score as rating, {$score} as score, ROW_NUMBER() OVER (ORDER BY {$score} DESC, u.id ASC) as position");
        $total = DB::query()->fromSub(clone $ranked, 'ranked')->count();
        $size = max(1, min(25, (int) config('ranking.page_size', 10)));
        $pages = max(1, (int) ceil($total / $size));
        $page = max(1, min($pages, $page));
        $format = fn ($r) => ['position' => (int) $r->position, 'display_name' => $this->safeName($r), 'tier' => $this->rank((int) $r->rating)['tier'], 'score' => (int) $r->score];
        $rows = DB::query()->fromSub(clone $ranked, 'ranked')->orderBy('position')->offset(($page - 1) * $size)->limit($size)->get()->map($format)->all();
        $own = DB::query()->fromSub($ranked, 'ranked')->where('user_id', $viewer?->id ?? -1)->first();

        return ['rows' => $rows, 'own' => $own ? $format($own) : null, 'page' => $page, 'pages' => $pages, 'total' => $total, 'period' => $period];
    }

    public function ratingTotals(string $start, string $end): Builder
    {
        $plain = DB::table('game_rating_events')->whereNull('participant_deltas')->where('created_at', '>=', $start)->where('created_at', '<', $end)->select('user_id', 'delta');
        $compound = DB::table('game_rating_events as e')->crossJoin(DB::raw('LATERAL json_each_text(e.participant_deltas) as d'))
            ->whereNotNull('e.participant_deltas')->where('e.created_at', '>=', $start)->where('e.created_at', '<', $end)->selectRaw('CAST(d.key AS bigint) as user_id, CAST(d.value AS integer) as delta');

        return DB::query()->fromSub($plain->unionAll($compound), 'deltas')->selectRaw('user_id, SUM(delta) as score')->groupBy('user_id');
    }

    private function safeName(object $row): string
    {
        $name = (string) $row->display_name;
        if (app(ContactInformationGuard::class)->blocked($name) || preg_match('/[0-9]{6,}/', $name)
            || ($row->telegram_username && stripos($name, $row->telegram_username) !== false)) {
            return 'Player';
        }

        return mb_substr(str_replace(["\n", "\r"], ' ', $name), 0, 60);
    }
}
