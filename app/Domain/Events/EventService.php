<?php

namespace App\Domain\Events;

use App\Domain\Moderation\RestrictionService;
use App\Domain\Telegram\SocialNotificationService;
use App\Domain\Users\BlockService;
use App\Domain\Users\User;
use App\Domain\Users\UserStatus;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class EventService
{
    public function __construct(private readonly BlockService $blocks, private readonly SocialNotificationService $notifications) {}

    public function create(User $creator, array $data): Event
    {
        app(RestrictionService::class)->authorize($creator, 'event_creation_disabled');
        if (trim((string) ($data['title'] ?? '')) === '' || mb_strlen($data['title']) > 120) {
            throw new \DomainException('Event title is invalid.');
        }$category = EventCategory::find($data['category_id'] ?? 0);
        if (! $category) {
            throw new \DomainException('Event category is invalid.');
        }$starts = CarbonImmutable::parse($data['starts_at'] ?? '');
        if ($starts->lt(now())) {
            throw new \DomainException('Event must start in the future.');
        }if (isset($data['capacity']) && $data['capacity'] !== null && (int) $data['capacity'] < 1) {
            throw new \DomainException('Capacity is invalid.');
        }

        return Event::create(['creator_user_id' => $creator->id, 'title' => trim($data['title']), 'description' => $data['description'] ?? null, 'category_id' => $category->id, 'starts_at' => $starts, 'ends_at' => $data['ends_at'] ?? null, 'capacity' => $data['capacity'] ?? null, 'city_id' => $data['city_id'] ?? null, 'latitude' => $data['latitude'] ?? null, 'longitude' => $data['longitude'] ?? null, 'location_updated_at' => isset($data['latitude']) ? now() : null, 'status' => EventStatus::Published]);
    }

    public function join(User $user, Event $event): void
    {
        DB::transaction(function () use ($user, $event) {
            $e = Event::whereKey($event->id)->lockForUpdate()->firstOrFail();
            $creator = User::findOrFail($e->creator_user_id);
            app(RestrictionService::class)->active($user);
            app(RestrictionService::class)->active($creator);
            if ($user->status !== UserStatus::Active || ! $e->isJoinable() || $this->blocks->isBlocked($user, $creator)) {
                throw new \DomainException('This event cannot be joined.');
            }$existing = DB::table('event_participants')->where(['event_id' => $e->id, 'user_id' => $user->id])->lockForUpdate()->first();
            if ($existing && $existing->status === 'joined') {
                throw new \DomainException('Already joined.');
            }$count = DB::table('event_participants')->where('event_id', $e->id)->where('status', 'joined')->count();
            if ($e->capacity !== null && $count >= $e->capacity) {
                throw new \DomainException('Event is full.');
            }if ($existing) {
                DB::table('event_participants')->where('id', $existing->id)->update(['status' => 'joined', 'joined_at' => now(), 'left_at' => null, 'updated_at' => now()]);
            } else {
                DB::table('event_participants')->insert(['event_id' => $e->id, 'user_id' => $user->id, 'status' => 'joined', 'joined_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            }$this->notifications->queue($e->creator, 'event_joined', $e->id, __('A participant joined your event.'), 'event_joined:'.$e->id.':'.$user->id);
        });
    }

    public function leave(User $user, Event $event): void
    {
        DB::table('event_participants')->where(['event_id' => $event->id, 'user_id' => $user->id, 'status' => 'joined'])->update(['status' => 'left', 'left_at' => now(), 'updated_at' => now()]);
    }

    public function cancel(User $creator, Event $event): void
    {
        if ($event->creator_user_id !== $creator->id) {
            throw new \DomainException('Only the creator can cancel.');
        }DB::transaction(function () use ($event) {
            $e = Event::whereKey($event->id)->lockForUpdate()->firstOrFail();
            if ($e->status === EventStatus::Cancelled) {
                return;
            }
            $e->update(['status' => EventStatus::Cancelled]);
            foreach ($e->participants()->wherePivot('status', 'joined')->get() as $p) {
                $this->notifications->queue($p, 'event_cancelled', $e->id, __('An event you joined was cancelled.'), 'event_cancelled:'.$e->id.':'.$p->id);
            }
        });
    }

    public function listing(User $user, string $filter = 'new', int $page = 1, ?int $categoryId = null): LengthAwarePaginator
    {
        $query = Event::with(['category', 'city', 'creator.profile']);
        if ($filter === 'mine') {
            $query->where('creator_user_id', $user->id);
        } else {
            $query->where('status', 'published')->where('starts_at', '>', now());
        }
        if ($filter === 'today') {
            $query->whereBetween('starts_at', [now()->startOfDay(), now()->endOfDay()]);
        } elseif ($filter === 'week') {
            $query->whereBetween('starts_at', [now()->startOfWeek(), now()->endOfWeek()]);
        } elseif ($filter === 'category' && $categoryId) {
            $query->where('category_id', $categoryId);
        } elseif ($filter === 'joined') {
            $query->whereHas('participants', fn ($q) => $q->where('user_id', $user->id)->where('event_participants.status', 'joined'));
        }
        $query->orderBy($filter === 'new' ? 'created_at' : 'starts_at', 'desc');

        return $query->paginate(5, ['*'], 'page', max(1, $page));
    }

    public function participants(User $viewer, Event $event, int $page = 1): LengthAwarePaginator
    {
        return $event->participants()->with(['profile.city'])->wherePivot('status', 'joined')->orderBy('event_participants.joined_at')->paginate(5, ['users.*'], 'page', max(1, $page));
    }

    public function nearby(User $user, float $min, float $max, int $page = 1): LengthAwarePaginator
    {
        $p = $user->profile()->first();
        if (! $p?->latitude || ! $p?->longitude) {
            return new LengthAwarePaginator([], 0, 5, $page);
        }$lat = (float) $p->latitude;
        $lon = (float) $p->longitude;
        $expr = "(6371 * acos(least(1, greatest(-1, cos(radians({$lat})) * cos(radians(events.latitude)) * cos(radians(events.longitude) - radians({$lon})) + sin(radians({$lat})) * sin(radians(events.latitude))))))";

        return Event::with(['category', 'city', 'creator.profile'])->where('status', 'published')->where('starts_at', '>', now())->whereNotNull('latitude')->whereNotNull('longitude')->whereBetween(DB::raw($expr), [$min, $max])->addSelect(DB::raw($expr.' as distance_km'))->orderBy('starts_at')->paginate(5, ['*'], 'page', $page);
    }

    public function report(User $reporter, Event $event, EventReportReason $reason, ?string $description = null): void
    {
        DB::table('event_reports')->insertOrIgnore(['event_id' => $event->id, 'reporter_user_id' => $reporter->id, 'reason' => $reason->value, 'description' => $description, 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
    }
}
