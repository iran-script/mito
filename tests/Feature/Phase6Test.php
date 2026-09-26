<?php

namespace Tests\Feature;

use App\Domain\Events\Event;
use App\Domain\Events\EventCategory;
use App\Domain\Events\EventReportReason;
use App\Domain\Events\EventService;
use App\Domain\Events\EventStatus;
use App\Domain\Profiles\City;
use App\Domain\Profiles\Gender;
use App\Domain\Profiles\Profile;
use App\Domain\Profiles\ProfileStatus;
use App\Domain\Users\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Phase6Test extends TestCase
{
    use RefreshDatabase;

    private City $city;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->city = City::first();
    }

    private function user(int $n): User
    {
        $u = User::create(['telegram_user_id' => 770000 + $n, 'last_activity_at' => now()]);
        Profile::create(['user_id' => $u->id, 'display_name' => 'Event User '.$n, 'birth_date' => '2000-01-01', 'gender' => Gender::Male, 'city_id' => $this->city->id, 'status' => ProfileStatus::Active, 'profile_completed_at' => now()]);

        return $u->fresh('profile');
    }

    private function event(User $u, ?int $capacity = null): Event
    {
        return app(EventService::class)->create($u, ['title' => 'Hike', 'description' => 'A safe hike', 'category_id' => EventCategory::first()->id, 'starts_at' => now()->addDay(), 'capacity' => $capacity, 'city_id' => $this->city->id]);
    }

    public function test_event_creation_and_join_capacity_leave_and_cancel(): void
    {
        $creator = $this->user(1);
        $one = $this->user(2);
        $two = $this->user(3);
        $e = $this->event($creator, 1);
        $service = app(EventService::class);
        $service->join($one, $e);
        try {
            $service->join($two, $e);
            $this->fail();
        } catch (\DomainException $x) {
        }$service->leave($one, $e);
        $service->join($two, $e);
        $service->cancel($creator, $e);
        $this->assertSame(EventStatus::Cancelled, $e->fresh()->status);
    }

    public function test_duplicate_join_and_non_creator_cancel_are_rejected(): void
    {
        $creator = $this->user(4);
        $member = $this->user(5);
        $e = $this->event($creator);
        $service = app(EventService::class);
        $service->join($member, $e);
        try {
            $service->join($member, $e);
            $this->fail();
        } catch (\DomainException $x) {
        }$this->expectException(\DomainException::class);
        $service->cancel($member, $e);
    }

    public function test_nearby_events_are_server_filtered_and_require_requester_location(): void
    {
        $creator = $this->user(6);
        $requester = $this->user(7);
        $requester->profile->update(['latitude' => 35.70, 'longitude' => 51.40]);
        $e = $this->event($creator);
        $e->update(['latitude' => 35.71, 'longitude' => 51.40, 'location_updated_at' => now()]);
        $results = app(EventService::class)->nearby($requester, 0, 5);
        $this->assertCount(1, $results);
        $this->assertNotNull($results->first()->distance_km);
        $other = $this->user(8);
        $this->assertCount(0, app(EventService::class)->nearby($other, 0, 5));
    }

    public function test_event_report_is_idempotent_and_join_notification_is_queued(): void
    {
        $creator = $this->user(9);
        $member = $this->user(10);
        $e = $this->event($creator);
        $service = app(EventService::class);
        $service->join($member, $e);
        $this->assertDatabaseCount('social_outbox', 1);
        $service->report($member, $e, EventReportReason::Spam, 'Unsafe');
        $service->report($member, $e, EventReportReason::Spam, 'Unsafe');
        $this->assertDatabaseCount('event_reports', 1);
    }
}
