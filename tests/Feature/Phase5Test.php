<?php

namespace Tests\Feature;

use App\Domain\Chat\ChatRequest;
use App\Domain\Contacts\ContactService;
use App\Domain\Contacts\UserContact;
use App\Domain\Discovery\DiscoveryService;
use App\Domain\Memberships\BulkChatRequestService;
use App\Domain\Memberships\BulkDirectMessageService;
use App\Domain\Memberships\GoldLimitsService;
use App\Domain\Memberships\GoldMembershipService;
use App\Domain\Memberships\Membership;
use App\Domain\Payments\CoinTransaction;
use App\Domain\Payments\CoinTransactionType;
use App\Domain\Payments\WalletService;
use App\Domain\Profiles\City;
use App\Domain\Profiles\Gender;
use App\Domain\Profiles\Profile;
use App\Domain\Profiles\ProfileStatus;
use App\Domain\Telegram\Http\WebhookRequest;
use App\Domain\Users\BlockService;
use App\Domain\Users\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Phase5Test extends TestCase
{
    use RefreshDatabase;

    private City $city;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->city = City::first();
    }

    private function user(int $id = 0): User
    {
        $user = User::create(['telegram_user_id' => 880000 + ($id ?: random_int(1, 99999)), 'telegram_username' => 'user'.($id ?: random_int(1, 99999)), 'last_activity_at' => now()]);
        Profile::create(['user_id' => $user->id, 'display_name' => 'User '.$user->id, 'birth_date' => '2000-01-01', 'gender' => Gender::Male, 'city_id' => $this->city->id, 'status' => ProfileStatus::Active, 'profile_completed_at' => now()]);

        return $user->fresh(['profile']);
    }

    private function gold(User $user, int $days = 30): Membership
    {
        return app(GoldMembershipService::class)->activate($user, now()->addDays($days));
    }

    public function test_contacts_are_private_idempotent_and_block_aware(): void
    {
        $owner = $this->user(1);
        $contact = $this->user(2);
        $service = app(ContactService::class);
        $service->add($owner, $contact);
        $service->add($owner, $contact);
        $this->assertSame(1, UserContact::where('user_id', $owner->id)->count());
        $this->assertSame(0, UserContact::where('user_id', $contact->id)->count());
        $service->remove($owner, $contact);
        $this->assertDatabaseMissing('user_contacts', ['user_id' => $owner->id, 'contact_user_id' => $contact->id]);
        app(BlockService::class)->block($owner, $contact);
        $this->expectException(\DomainException::class);
        $service->add($owner, $contact);
    }

    public function test_membership_window_and_gold_priority_are_time_aware(): void
    {
        $requester = $this->user(3);
        $normal = $this->user(4);
        $gold = $this->user(5);
        $this->gold($gold);
        $normal->update(['last_activity_at' => now()->subMinute()]);
        $results = app(DiscoveryService::class)->city($requester, Gender::Male);
        $this->assertSame($gold->id, $results->first()->user_id);
        $this->assertTrue(app(GoldMembershipService::class)->isGold($gold));
        Membership::where('user_id', $gold->id)->update(['ends_at' => now()->subMinute()]);
        $this->assertFalse(app(GoldMembershipService::class)->isGold($gold));
    }

    public function test_bulk_chat_is_free_tracks_successes_and_is_idempotent(): void
    {
        $sender = $this->user(6);
        $this->gold($sender);
        $one = $this->user(7);
        $two = $this->user(8);
        $result = app(BulkChatRequestService::class)->send($sender, [$one, $two], 'phase5-chat-1');
        $this->assertSame(2, $result['sent']);
        $this->assertSame(2, ChatRequest::where('requester_user_id', $sender->id)->count());
        $this->assertSame(0, CoinTransaction::where('user_id', $sender->id)->count());
        $retry = app(BulkChatRequestService::class)->send($sender, [$one, $two], 'phase5-chat-1');
        $this->assertSame(2, $retry['sent']);
        $this->assertSame(1, DB::table('gold_usage_events')->where('idempotency_key', 'phase5-chat-1')->count());
    }

    public function test_bulk_direct_charges_current_price_and_delivers_individual_messages(): void
    {
        $sender = $this->user(9);
        $this->gold($sender);
        $one = $this->user(10);
        $two = $this->user(11);
        $wallets = app(WalletService::class);
        $wallets->credit($sender, 10, CoinTransactionType::Bonus, 'test', ['idempotency_key' => 'phase5-credit']);
        $result = app(BulkDirectMessageService::class)->send($sender, [$one, $two], 'Hello friends', 'phase5-direct-1');
        $this->assertSame(2, $result['sent']);
        $this->assertSame(6, $sender->fresh()->wallet->balance);
        $this->assertDatabaseCount('direct_messages', 2);
        $this->assertSame(2, DB::table('social_outbox')->where('source_type', 'direct_message')->count());
    }

    public function test_bulk_direct_contact_guard_and_insufficient_balance_send_nothing(): void
    {
        $sender = $this->user(12);
        $this->gold($sender);
        $recipient = $this->user(13);
        $this->expectException(\DomainException::class);
        app(BulkDirectMessageService::class)->send($sender, [$recipient], '@someone', 'phase5-blocked');
        $wallets = app(WalletService::class);
        $wallets->wallet($sender);
        try {
            app(BulkDirectMessageService::class)->send($sender, [$recipient], 'Hello', 'phase5-insufficient');
        } catch (Throwable $e) {
            $this->assertStringContainsString('requires', $e->getMessage());
        }
        $this->assertDatabaseCount('direct_messages', 0);
    }

    public function test_gold_limits_are_database_configurable(): void
    {
        $user = $this->user(14);
        $this->gold($user);
        DB::table('economy_settings')->where('key', 'gold_bulk_max_recipients_per_action')->update(['value' => '2']);
        $this->assertSame(2, app(GoldLimitsService::class)->maxRecipients());
        DB::table('economy_settings')->where('key', 'gold_bulk_chat_requests_per_day')->update(['value' => '1']);
        $this->assertSame(1, app(GoldLimitsService::class)->dailyChat($user));
    }

    public function test_contact_owner_can_remove_and_duplicate_removal_is_safe(): void
    {
        $owner = $this->user(15);
        $contact = $this->user(16);
        $service = app(ContactService::class);
        $service->add($owner, $contact);
        $this->assertTrue($service->removeById($owner, $contact->id));
        $this->assertFalse($service->removeById($owner, $contact->id));
        $this->assertDatabaseMissing('user_contacts', ['user_id' => $owner->id, 'contact_user_id' => $contact->id]);
    }

    public function test_another_user_cannot_remove_someone_elses_contact(): void
    {
        $owner = $this->user(17);
        $contact = $this->user(18);
        $other = $this->user(19);
        $service = app(ContactService::class);
        $service->add($owner, $contact);
        $this->assertFalse($service->removeById($other, $contact->id));
        $this->assertDatabaseHas('user_contacts', ['user_id' => $owner->id, 'contact_user_id' => $contact->id]);
    }

    public function test_contact_removal_callback_is_allowlisted_and_back_remains_valid(): void
    {
        $request = new WebhookRequest;
        $data = ['update_id' => 1, 'callback_query' => ['id' => 'c', 'data' => 'd:1:remove_contact_2', 'from' => ['id' => 10, 'is_bot' => false, 'first_name' => 'A'], 'message' => ['chat' => ['id' => 10, 'type' => 'private'], 'message_id' => 1]]];
        $this->assertFalse(validator($data, $request->rules())->fails());
        $data['callback_query']['data'] = 'd:1:remove_contact_2 OR 1=1';
        $this->assertTrue(validator($data, $request->rules())->fails());
    }
}
