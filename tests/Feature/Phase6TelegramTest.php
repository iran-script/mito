<?php

namespace Tests\Feature;

use App\Domain\Events\EventCategory;
use App\Domain\Events\EventReminderService;
use App\Domain\Events\EventService;
use App\Domain\Profiles\City;
use App\Domain\Profiles\Gender;
use App\Domain\Profiles\Profile;
use App\Domain\Profiles\ProfileStatus;
use App\Domain\Profiles\RegistrationState;
use App\Domain\Telegram\DiscoveryInteraction;
use App\Domain\Telegram\IncomingUpdate;
use App\Domain\Users\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Phase6TelegramTest extends TestCase
{
    use RefreshDatabase;

    private City $city;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->city = City::first();
    }

    private function user(string $name = 'Event User'): User
    {
        $user = User::create(['telegram_user_id' => random_int(1000000, 9999999), 'last_activity_at' => now()]);
        Profile::create(['user_id' => $user->id, 'display_name' => $name, 'birth_date' => '2000-01-01', 'gender' => Gender::Male, 'city_id' => $this->city->id, 'status' => ProfileStatus::Active, 'profile_completed_at' => now()]);

        return $user->fresh('profile');
    }

    private function update(User $user, ?string $callback = null, ?string $text = null): IncomingUpdate
    {
        $root = ['message_id' => 1, 'chat' => ['id' => $user->telegram_user_id, 'type' => 'private'], 'from' => ['id' => $user->telegram_user_id, 'is_bot' => false, 'first_name' => 'User']];
        if ($callback) {
            return new IncomingUpdate(['update_id' => random_int(1, 999999), 'callback_query' => ['id' => 'cb', 'from' => $root['from'], 'message' => ['message_id' => 1, 'chat' => $root['chat']], 'data' => $callback]]);
        }
        if ($text !== null) {
            $root['text'] = $text;
        }

        return new IncomingUpdate(['update_id' => random_int(1, 999999), 'message' => $root]);
    }

    public function test_events_menu_and_back_are_available(): void
    {
        $user = $this->user();
        $state = RegistrationState::create(['user_id' => $user->id, 'step' => 'complete']);
        $result = app(DiscoveryInteraction::class)->handle($user, $this->update($user, 'd:0:events'), $state);
        $this->assertStringContainsString('Events', $result[0]['parameters']['text']);
        $back = app(DiscoveryInteraction::class)->handle($user, $this->update($user, 'd:0:events_back'), $state);
        $this->assertStringContainsString('Events', $back[0]['parameters']['text']);
    }

    public function test_creation_wizard_persists_category_and_publishes(): void
    {
        $user = $this->user();
        $state = RegistrationState::create(['user_id' => $user->id, 'step' => 'complete']);
        $interaction = app(DiscoveryInteraction::class);
        $interaction->handle($user, $this->update($user, 'd:0:event_create'), $state);
        $interaction->handle($user, $this->update($user, null, 'Community hike'), $state);
        $category = EventCategory::first();
        $interaction->handle($user, $this->update($user, 'd:0:event_wizard_category_'.$category->id), $state);
        $interaction->handle($user, $this->update($user, null, 'Bring water'), $state);
        $interaction->handle($user, $this->update($user, null, now()->addDay()->format('Y-m-d H:i')), $state);
        $interaction->handle($user, $this->update($user, null, '0'), $state);
        $result = $interaction->handle($user, $this->update($user, 'd:0:event_location_city'), $state);
        $this->assertStringContainsString('Event step 8 of 8', $result[0]['parameters']['text']);
        $interaction->handle($user, $this->update($user, 'd:0:event_publish'), $state);
        $this->assertDatabaseHas('events', ['creator_user_id' => $user->id, 'title' => 'Community hike']);
    }

    public function test_event_listing_paginates_without_repeating_records(): void
    {
        $creator = $this->user('Creator');
        for ($i = 1; $i <= 7; $i++) {
            app(EventService::class)->create($creator, ['title' => 'Event '.$i, 'category_id' => EventCategory::first()->id, 'starts_at' => now()->addDays($i), 'city_id' => $this->city->id]);
        }
        $first = app(EventService::class)->listing($creator, 'new', 1);
        $second = app(EventService::class)->listing($creator, 'new', 2);
        $this->assertCount(5, $first);
        $this->assertEmpty(array_intersect($first->pluck('id')->all(), $second->pluck('id')->all()));
    }

    public function test_join_leave_callbacks_are_safe_and_capacity_is_released(): void
    {
        $creator = $this->user('Creator');
        $member = $this->user('Member');
        $event = app(EventService::class)->create($creator, ['title' => 'Meetup', 'category_id' => EventCategory::first()->id, 'starts_at' => now()->addDay(), 'capacity' => 1, 'city_id' => $this->city->id]);
        app(EventService::class)->join($member, $event);
        app(EventService::class)->leave($member, $event);
        $this->assertDatabaseHas('event_participants', ['event_id' => $event->id, 'user_id' => $member->id, 'status' => 'left']);
    }

    public function test_reminders_are_queued_once_and_cancelled_events_are_skipped(): void
    {
        $creator = $this->user('Creator');
        $member = $this->user('Member');
        $event = app(EventService::class)->create($creator, ['title' => 'Soon', 'category_id' => EventCategory::first()->id, 'starts_at' => now()->addMinutes(30), 'city_id' => $this->city->id]);
        app(EventService::class)->join($member, $event);
        $service = app(EventReminderService::class);
        $this->assertSame(1, $service->queueDue());
        $this->assertSame(0, $service->queueDue());
        $this->assertDatabaseCount('event_reminders', 1);
        app(EventService::class)->cancel($creator, $event);
        $this->assertSame(0, $service->queueDue());
    }
}
