<?php

namespace Tests\Feature;

use App\Domain\Discovery\ActivityPresenter;
use App\Domain\Discovery\DiscoveryService;
use App\Domain\Discovery\DistanceRange;
use App\Domain\Profiles\City;
use App\Domain\Profiles\Gender;
use App\Domain\Profiles\Interest;
use App\Domain\Profiles\LocationService;
use App\Domain\Profiles\Profile;
use App\Domain\Profiles\ProfileStatus;
use App\Domain\Profiles\PublicProfile;
use App\Domain\Users\User;
use App\Domain\Users\UserStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DiscoveryTest extends TestCase
{
    use RefreshDatabase;

    private DiscoveryService $service;

    private City $city;

    private City $otherCity;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->travelTo(CarbonImmutable::parse('2026-09-17 12:00:00'));
        $this->service = app(DiscoveryService::class);
        $this->city = City::first();
        $this->otherCity = City::where('id', '>', $this->city->id)->first();
    }

    private function user(string $name = 'Requester', Gender $gender = Gender::Male, int $age = 26, ?City $city = null, bool $active = true): User
    {
        $user = User::create(['telegram_user_id' => random_int(100000, 999999999), 'telegram_first_name' => $name, 'last_activity_at' => now()->subMinutes(random_int(1, 100))]);
        $birth = now()->subYears($age)->subDays(3)->toDateString();
        $profile = Profile::create(['user_id' => $user->id, 'display_name' => $name, 'birth_date' => $birth, 'gender' => $gender, 'city_id' => ($city ?? $this->city)->id, 'status' => $active ? ProfileStatus::Active : ProfileStatus::Draft, 'profile_completed_at' => $active ? now()->subDay() : null]);
        if ($active) {
            $user->update(['status' => UserStatus::Active]);
        }

        return $user->fresh(['profile']);
    }

    public function test_incomplete_suspended_banned_deleted_and_self_are_excluded(): void
    {
        $requester = $this->user();
        $this->user('draft', Gender::Female, 26, $this->city, false);
        foreach ([UserStatus::Suspended, UserStatus::Banned, UserStatus::Deleted] as $status) {
            $u = $this->user($status->value, Gender::Female);
            $u->update(['status' => $status]);
        }
        $this->user('eligible', Gender::Female);
        $result = $this->service->city($requester, Gender::Female);
        $this->assertCount(1, $result);
        $this->assertSame('eligible', $result->first()->display_name);
    }

    public function test_wrong_gender_and_other_city_are_excluded(): void
    {
        $requester = $this->user();
        $this->user('male', Gender::Male);
        $this->user('other-city', Gender::Female, 26, $this->otherCity);
        $this->assertCount(0, $this->service->city($requester, Gender::Female));
    }

    public function test_same_city_works(): void
    {
        $r = $this->user();
        $this->user('same', Gender::Female);
        $this->assertCount(1, $this->service->city($r, Gender::Female));
    }

    public function test_exact_same_age_works_and_different_age_is_excluded(): void
    {
        $r = $this->user(age: 26);
        $this->user('same', Gender::Female, 26);
        $this->user('different', Gender::Female, 27);
        $this->assertSame(['same'], $this->service->age($r, Gender::Female)->pluck('display_name')->all());
    }

    public function test_new_users_use_configurable_seven_day_window(): void
    {
        $r = $this->user();
        $new = $this->user('new', Gender::Female);
        $new->profile->update(['profile_completed_at' => now()->subDays(2)]);
        $old = $this->user('old', Gender::Female);
        $old->profile->update(['profile_completed_at' => now()->subDays(8)]);
        $this->assertSame(['new'], $this->service->newUsers($r, Gender::Female)->pluck('display_name')->all());
    }

    public function test_interest_filter_does_not_duplicate_users(): void
    {
        $r = $this->user();
        $interest = Interest::first();
        $candidate = $this->user('interested', Gender::Female);
        $candidate->profile->interests()->attach([$interest->id]);
        $this->assertCount(1, $this->service->interest($r, $interest->id, Gender::Female));
    }

    public function test_pagination_is_five_and_pages_do_not_repeat(): void
    {
        $r = $this->user();
        for ($i = 1; $i <= 11; $i++) {
            $this->user('user'.$i, Gender::Female);
        }
        $first = $this->service->city($r, Gender::Female, 1);
        $second = $this->service->city($r, Gender::Female, 2);
        $this->assertCount(5, $first);
        $this->assertCount(5, $second);
        $this->assertEmpty(array_intersect($first->pluck('id')->all(), $second->pluck('id')->all()));
    }

    public function test_anonymous_returns_one_and_avoids_recent_repetition(): void
    {
        $r = $this->user();
        $a = $this->user('a', Gender::Female);
        $b = $this->user('b', Gender::Female);
        $one = $this->service->anonymous($r, Gender::Female);
        $two = $this->service->anonymous($r, Gender::Female);
        $this->assertNotNull($one);
        $this->assertNotNull($two);
        $this->assertNotSame($one->profile->id, $two->profile->id);
        $this->assertDatabaseCount('discovery_histories', 2);
    }

    public function test_anonymous_handles_no_eligible_users(): void
    {
        $this->assertNull($this->service->anonymous($this->user(), Gender::Female));
    }

    private function locate(User $user, float $lat, float $lon): void
    {
        app(LocationService::class)->update($user, $lat, $lon);
    }

    public function test_nearby_ranges_and_missing_location(): void
    {
        $r = $this->user();
        $this->locate($r, 35.7000, 51.4000);
        foreach ([['near5', 35.718, 51.4, DistanceRange::ZeroToFive], ['near10', 35.780, 51.4, DistanceRange::FiveToTen], ['near15', 35.825, 51.4, DistanceRange::TenToFifteen], ['near20', 35.870, 51.4, DistanceRange::FifteenToTwenty]] as [$name, $lat, $lon, $range]) {
            $u = $this->user($name, Gender::Female);
            $this->locate($u, $lat, $lon);
        }
        $this->user('no-location', Gender::Female);
        $this->assertSame(['near5'], $this->service->distance($r, Gender::Female, DistanceRange::ZeroToFive)->pluck('display_name')->all());
        $this->assertSame(['near10'], $this->service->distance($r, Gender::Female, DistanceRange::FiveToTen)->pluck('display_name')->all());
        $this->assertSame(['near15'], $this->service->distance($r, Gender::Female, DistanceRange::TenToFifteen)->pluck('display_name')->all());
        $this->assertSame(['near20'], $this->service->distance($r, Gender::Female, DistanceRange::FifteenToTwenty)->pluck('display_name')->all());
        $this->assertCount(4, $this->service->distance($r, Gender::Female, DistanceRange::ZeroToTwenty));
    }

    public function test_requester_without_location_returns_empty(): void
    {
        $this->assertCount(0, $this->service->distance($this->user(), Gender::Female, DistanceRange::ZeroToTwenty));
    }

    public function test_block_and_reverse_block_are_excluded(): void
    {
        $r = $this->user();
        $blocked = $this->user('blocked', Gender::Female);
        $reverse = $this->user('reverse', Gender::Female);
        $good = $this->user('good', Gender::Female);
        DB::table('user_blocks')->insert([['blocker_user_id' => $r->id, 'blocked_user_id' => $blocked->id], ['blocker_user_id' => $reverse->id, 'blocked_user_id' => $r->id]]);
        $this->assertSame(['good'], $this->service->city($r, Gender::Female)->pluck('display_name')->all());
    }

    public function test_activity_ordering_and_activity_labels(): void
    {
        $r = $this->user();
        $recent = $this->user('recent', Gender::Female);
        $recent->update(['last_activity_at' => now()->subMinutes(2)]);
        $old = $this->user('old', Gender::Female);
        $old->update(['last_activity_at' => now()->subHours(2)]);
        $this->assertSame(['recent', 'old'], $this->service->city($r, Gender::Female)->pluck('display_name')->all());
        $this->assertSame('active now', ActivityPresenter::label(now()));
        $this->assertSame('active 2 hours ago', ActivityPresenter::label(now()->subHours(2)));
    }

    public function test_location_timestamp_is_updated_and_public_profile_hides_private_fields(): void
    {
        $r = $this->user();
        $this->locate($r, 35.7, 51.4);
        $r->refresh();
        $this->assertNotNull($r->profile->location_updated_at);
        $this->assertArrayNotHasKey('latitude', $r->profile->toArray());
        $candidate = $this->user('public', Gender::Female);
        $public = PublicProfile::fromProfile($candidate->profile->load(['city', 'interests', 'user']));
        $text = $public->text();
        $this->assertStringNotContainsString((string) $candidate->telegram_user_id, $text);
        $this->assertStringNotContainsString($candidate->telegram_username ?? 'never', $text);
        $this->assertStringNotContainsString('35.7', $text);
    }
}
