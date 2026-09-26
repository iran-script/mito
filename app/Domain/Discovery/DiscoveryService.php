<?php

namespace App\Domain\Discovery;

use App\Domain\Moderation\RestrictionService;
use App\Domain\Profiles\Gender;
use App\Domain\Profiles\Profile;
use App\Domain\Profiles\ProfileStatus;
use App\Domain\Users\User;
use App\Domain\Users\UserStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class DiscoveryService
{
    private int $pageSize;

    public function __construct(?int $pageSize = null)
    {
        $this->pageSize = $pageSize ?? (int) config('discovery.page_size', 5);
    }

    public function city(User $requester, Gender $gender, int $page = 1): LengthAwarePaginator
    {
        return $this->list($requester, DiscoveryType::City, $gender, $page, fn (Builder $q) => $q->where('profiles.city_id', $requester->profile?->city_id));
    }

    public function age(User $requester, Gender $gender, int $page = 1, int $tolerance = 0): LengthAwarePaginator
    {
        $birthDate = $requester->profile?->birth_date;
        if (! $birthDate) {
            return $this->emptyPaginator($page);
        }
        $age = $birthDate->age;
        $today = CarbonImmutable::today();
        $youngest = $today->subYears($age + 1 - $tolerance)->addDay();
        $oldest = $today->subYears(max(0, $age - $tolerance));

        return $this->list($requester, DiscoveryType::Age, $gender, $page, fn (Builder $q) => $q->whereBetween('profiles.birth_date', [$youngest->toDateString(), $oldest->toDateString()]));
    }

    public function newUsers(User $requester, Gender $gender, int $page = 1): LengthAwarePaginator
    {
        return $this->list($requester, DiscoveryType::NewUsers, $gender, $page, fn (Builder $q) => $q->where('profiles.profile_completed_at', '>=', now()->subDays((int) config('discovery.new_user_days', 7)))->orderByDesc('profiles.profile_completed_at'));
    }

    public function interest(User $requester, int $interestId, Gender $gender, int $page = 1): LengthAwarePaginator
    {
        return $this->list($requester, DiscoveryType::Interest, $gender, $page, fn (Builder $q) => $q->whereHas('interests', fn (Builder $i) => $i->whereKey($interestId)));
    }

    public function distance(User $requester, Gender $gender, DistanceRange $range, int $page = 1): LengthAwarePaginator
    {
        $origin = $requester->profile()->first();
        if (! $origin?->latitude || ! $origin?->longitude) {
            return $this->emptyPaginator($page);
        }
        [$min, $max] = $range->bounds();
        $distance = $this->distanceExpression((float) $origin->latitude, (float) $origin->longitude);

        return $this->list($requester, DiscoveryType::Distance, $gender, $page, function (Builder $q) use ($distance, $min, $max) {
            $q->whereNotNull('profiles.latitude')->whereNotNull('profiles.longitude')->whereBetween(DB::raw($distance), [$min, $max]);
        }, $distance);
    }

    public function anonymous(User $requester, Gender $gender): ?DiscoveryResult
    {
        $recent = DiscoveryHistory::where('requester_user_id', $requester->id)->where('discovery_type', DiscoveryType::Anonymous->value)->where('discovered_at', '>=', now()->subDays(30))->pluck('discovered_user_id');
        $candidate = $this->base($requester, DiscoveryType::Anonymous, $gender)->whereNotIn('profiles.user_id', $recent)->inRandomOrder()->first();
        if (! $candidate) {
            return null;
        }
        $this->recordHistory($requester, $candidate, DiscoveryType::Anonymous);

        return new DiscoveryResult($candidate);
    }

    public function record(User $requester, Profile $profile, DiscoveryType $type): void
    {
        $this->recordHistory($requester, $profile, $type);
    }

    private function list(User $requester, DiscoveryType $type, Gender $gender, int $page, \Closure $filter, ?string $distance = null): LengthAwarePaginator
    {
        $query = $this->base($requester, $type, $gender);
        $filter($query);
        if ($distance) {
            $query->addSelect(DB::raw("{$distance} as distance_km"));
        }

        $query->selectRaw("exists (select 1 from memberships where memberships.user_id = profiles.user_id and memberships.plan_code = 'gold' and memberships.status = 'active' and memberships.starts_at <= ? and memberships.ends_at > ?) as is_gold", [now(), now()]);

        $columns = $query->getQuery()->columns ?: ['*'];

        return $query->when((DB::table('economy_settings')->where('key', 'gold_priority_enabled')->value('value') ?? '1') === '1', fn ($q) => $q->orderByDesc('is_gold'))->orderByRaw('users.last_activity_at IS NULL')->orderByDesc('users.last_activity_at')->orderBy('users.id')->paginate($this->pageSize, $columns, 'page', max(1, $page));
    }

    private function base(User $requester, DiscoveryType $type, Gender $gender): Builder
    {
        app(RestrictionService::class)->active($requester);

        return Profile::query()->select('profiles.*')->with(['user', 'city', 'interests'])->join('users', 'users.id', '=', 'profiles.user_id')
            ->where('profiles.status', ProfileStatus::Active->value)->where('users.status', UserStatus::Active->value)
            ->where('profiles.gender', $gender->value)->where('profiles.user_id', '<>', $requester->id)
            ->whereNotExists(fn ($q) => $q->from('user_blocks')->whereColumn('user_blocks.blocker_user_id', 'users.id')->where('user_blocks.blocked_user_id', $requester->id))
            ->whereNotExists(fn ($q) => $q->from('user_blocks')->where('user_blocks.blocker_user_id', $requester->id)->whereColumn('user_blocks.blocked_user_id', 'users.id'));
    }

    private function distanceExpression(float $latitude, float $longitude): string
    {
        return "(6371 * acos(least(1, greatest(-1, cos(radians({$latitude})) * cos(radians(profiles.latitude)) * cos(radians(profiles.longitude) - radians({$longitude})) + sin(radians({$latitude})) * sin(radians(profiles.latitude))))))";
    }

    private function emptyPaginator(int $page): LengthAwarePaginator
    {
        return new LengthAwarePaginator([], 0, $this->pageSize, max(1, $page));
    }

    private function recordHistory(User $requester, Profile $profile, DiscoveryType $type): void
    {
        DB::table('discovery_histories')->insert(['requester_user_id' => $requester->id, 'discovered_user_id' => $profile->user_id, 'discovery_type' => $type->value, 'discovered_at' => now()]);
    }
}
