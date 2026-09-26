<?php

namespace Tests\Feature;

use App\Domain\Chat\ChatRequestService;
use App\Domain\Chat\ChatRequestStatus;
use App\Domain\Payments\WalletService;
use App\Domain\Profiles\City;
use App\Domain\Profiles\Gender;
use App\Domain\Profiles\Profile;
use App\Domain\Profiles\ProfileStatus;
use App\Domain\Telegram\SocialNotificationService;
use App\Domain\Users\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ChatRequestExpiryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->travelTo(CarbonImmutable::parse('2026-09-24 12:00:00'));
        config(['social.chat_request_ttl_seconds' => 120]);
    }

    private function user(string $name): User
    {
        $user = User::create([
            'telegram_user_id' => random_int(10000000, 99999999),
            'telegram_first_name' => $name,
            'last_activity_at' => now(),
        ]);
        Profile::create([
            'user_id' => $user->id,
            'display_name' => $name,
            'birth_date' => '2000-01-01',
            'gender' => Gender::Male,
            'city_id' => City::first()->id,
            'status' => ProfileStatus::Active,
            'profile_completed_at' => now(),
        ]);

        return $user->fresh('profile');
    }

    public function test_request_ttl_is_exactly_120_seconds_and_boundary_is_expired(): void
    {
        $a = $this->user('A');
        $b = $this->user('B');
        $service = app(ChatRequestService::class);
        $request = $service->create($a, $b);

        $this->assertEquals(120, $request->created_at->diffInSeconds($request->expires_at));
        $this->travel(119)->seconds();
        $seen = $service->view($b, $request)[0];
        $this->assertSame(ChatRequestStatus::Seen, $seen->status);

        $this->travel(1)->seconds();
        try {
            $service->accept($b, $request->fresh());
            $this->fail('The request must expire at the 120-second boundary.');
        } catch (\DomainException $e) {
            $this->assertSame('The request deadline has passed.', $e->getMessage());
        }
        $this->assertSame(ChatRequestStatus::Expired, $request->fresh()->status);
        $this->assertDatabaseCount('conversations', 0);
        $this->assertDatabaseCount('coin_transactions', 0);
    }

    public function test_expired_pending_and_seen_requests_allow_fresh_rows_and_preserve_history(): void
    {
        $a = $this->user('A');
        $b = $this->user('B');
        $service = app(ChatRequestService::class);

        $pending = $service->create($a, $b);
        $this->travel(120)->seconds();
        $fresh = $service->create($a, $b);
        $this->assertNotSame($pending->id, $fresh->id);
        $this->assertSame(ChatRequestStatus::Expired, $pending->fresh()->status);

        $service->view($b, $fresh);
        $this->travel(120)->seconds();
        $again = $service->create($a, $b);
        $this->assertNotSame($fresh->id, $again->id);
        $this->assertSame(ChatRequestStatus::Expired, $fresh->fresh()->status);
        $this->assertSame(ChatRequestStatus::Pending, $again->status);
        $this->assertDatabaseCount('chat_requests', 3);

        app(SocialNotificationService::class)->request($b, $again->id);
        $this->assertDatabaseHas('social_outbox', [
            'recipient_user_id' => $b->id,
            'source_type' => 'chat_request',
            'source_id' => $again->id,
        ]);
    }

    public function test_view_accept_and_reject_after_expiry_are_safe_and_free(): void
    {
        foreach (['view', 'accept', 'reject'] as $action) {
            $a = $this->user('A'.$action);
            $b = $this->user('B'.$action);
            app(WalletService::class)->wallet($a)->update(['balance' => 10]);
            $request = app(ChatRequestService::class)->create($a, $b);
            $this->travel(120)->seconds();

            try {
                app(ChatRequestService::class)->{$action}($b, $request);
                $this->fail("{$action} should reject an expired request.");
            } catch (\DomainException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
            $this->assertSame(ChatRequestStatus::Expired, $request->fresh()->status);
            $this->assertSame(10, app(WalletService::class)->wallet($a)->fresh()->balance);
            $this->assertDatabaseCount('conversations', 0);
            $this->travelBack();
            $this->travelTo(CarbonImmutable::parse('2026-09-24 12:00:00'));
        }
    }

    public function test_cleanup_expires_only_stale_pending_and_seen_and_is_idempotent(): void
    {
        $a = $this->user('A');
        $b = $this->user('B');
        $c = $this->user('C');
        $d = $this->user('D');
        $service = app(ChatRequestService::class);
        $pending = $service->create($a, $b);
        $seen = $service->create($c, $d);
        $service->view($d, $seen);
        $accepted = $service->create($a, $c);
        app(WalletService::class)->wallet($a)->update(['balance' => 10]);
        $service->accept($c, $accepted);
        $rejected = $service->create($b, $d);
        $service->reject($d, $rejected);

        $this->travel(120)->seconds();
        $this->assertSame(2, $service->expire());
        $this->assertSame(ChatRequestStatus::Expired, $pending->fresh()->status);
        $this->assertSame(ChatRequestStatus::Expired, $seen->fresh()->status);
        $this->assertSame(ChatRequestStatus::Accepted, $accepted->fresh()->status);
        $this->assertSame(ChatRequestStatus::Rejected, $rejected->fresh()->status);
        $this->assertSame(0, $service->expire());
        $this->assertSame(0, Artisan::call('chat:expire-requests'));
    }

    public function test_legacy_long_expiry_is_lazily_expired_but_active_chat_still_blocks(): void
    {
        $a = $this->user('A');
        $b = $this->user('B');
        $c = $this->user('C');
        $service = app(ChatRequestService::class);
        $legacy = $service->create($a, $b);
        $legacy->update(['expires_at' => now()->addDays(3)]);
        $this->travel(120)->seconds();

        $replacement = $service->create($a, $b);
        $this->assertSame(ChatRequestStatus::Expired, $legacy->fresh()->status);
        app(WalletService::class)->wallet($a)->update(['balance' => 10]);
        $service->accept($b, $replacement);

        $this->expectException(\DomainException::class);
        $service->create($a, $c);
    }
}
