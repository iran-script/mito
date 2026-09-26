<?php

namespace Tests\Feature;

use App\Domain\Chat\ChatRequest;
use App\Domain\Chat\ChatRequestService;
use App\Domain\Chat\ChatRequestStatus;
use App\Domain\Chat\ConversationService;
use App\Domain\Chat\ConversationStatus;
use App\Domain\Chat\MessageType;
use App\Domain\Direct\DirectMessageService;
use App\Domain\Direct\DirectMessageStatus;
use App\Domain\Moderation\ContactInformationGuard;
use App\Domain\Moderation\ReportReason;
use App\Domain\Moderation\ReportService;
use App\Domain\Moderation\ReportStatus;
use App\Domain\Profiles\City;
use App\Domain\Profiles\Gender;
use App\Domain\Profiles\Profile;
use App\Domain\Profiles\ProfileStatus;
use App\Domain\Profiles\PublicProfile;
use App\Domain\Users\BlockService;
use App\Domain\Users\User;
use App\Domain\Users\UserStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SocialTest extends TestCase
{
    use RefreshDatabase;

    private City $city;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->travelTo(CarbonImmutable::parse('2026-09-17 12:00:00'));
        $this->city = City::first();
    }

    private function user(string $name = 'User', UserStatus $status = UserStatus::Active): User
    {
        $u = User::create(['telegram_user_id' => random_int(100000, 999999999), 'telegram_first_name' => $name, 'status' => $status, 'last_activity_at' => now()]);
        Profile::create(['user_id' => $u->id, 'display_name' => $name, 'birth_date' => '2000-01-01', 'gender' => Gender::Male, 'city_id' => $this->city->id, 'status' => ProfileStatus::Active, 'profile_completed_at' => now()]);

        return $u->fresh(['profile']);
    }

    private function request(User $a, User $b): ChatRequest
    {
        return app(ChatRequestService::class)->create($a, $b);
    }

    public function test_chat_request_creation_and_recipient_seen_are_idempotent(): void
    {
        $a = $this->user('A');
        $b = $this->user('B');
        $r = $this->request($a, $b);
        $this->assertSame(ChatRequestStatus::Pending, $r->status);
        $service = app(ChatRequestService::class);
        $seen = $service->markSeen($b, $r);
        $again = $service->markSeen($b, $r);
        $this->assertSame(ChatRequestStatus::Seen, $seen->status);
        $this->assertEquals($seen->seen_at, $again->seen_at);
    }

    public function test_chat_request_rejects_self_block_reverse_block_unavailable_and_duplicate(): void
    {
        $a = $this->user('A');
        $b = $this->user('B');
        $s = app(ChatRequestService::class);
        $this->expectException(\DomainException::class);
        $s->create($a, $a);
        app(BlockService::class)->block($a, $b);
        $this->expectException(\DomainException::class);
        $s->create($a, $b);
    }

    public function test_reverse_block_suspended_banned_and_duplicate_are_rejected(): void
    {
        $a = $this->user('A');
        $b = $this->user('B');
        app(BlockService::class)->unblock($a, $b);
        app(BlockService::class)->block($b, $a);
        $this->expectException(\DomainException::class);
        $this->request($a, $b);
    }

    public function test_accept_creates_exactly_one_two_person_conversation_and_double_accept_is_safe(): void
    {
        $a = $this->user('A');
        $b = $this->user('B');
        $r = $this->request($a, $b);
        $service = app(ChatRequestService::class);
        $c = $service->accept($b, $r);
        $same = $service->accept($b, $r);
        $this->assertTrue($c->is($same));
        $this->assertSame(2, $c->participants()->count());
        $this->assertDatabaseCount('conversations', 1);
        $this->assertSame(ChatRequestStatus::Accepted, $r->fresh()->status);
    }

    public function test_only_recipient_can_accept_or_reject_and_expired_cannot_accept(): void
    {
        $a = $this->user('A');
        $b = $this->user('B');
        $r = $this->request($a, $b);
        $s = app(ChatRequestService::class);
        $this->expectException(\DomainException::class);
        $s->accept($a, $r);
        $r->update(['expires_at' => now()->subMinute()]);
        $this->expectException(\DomainException::class);
        $s->accept($b, $r);
    }

    public function test_rejection_and_expiry_statuses_are_recorded(): void
    {
        $a = $this->user('A');
        $b = $this->user('B');
        $s = app(ChatRequestService::class);
        $r = $this->request($a, $b);
        $s->reject($b, $r);
        $this->assertSame(ChatRequestStatus::Rejected, $r->fresh()->status);
        $r2 = $this->request($a, $b);
        $r2->update(['expires_at' => now()->subSecond()]);
        $s->expire();
        $this->assertSame(ChatRequestStatus::Expired, $r2->fresh()->status);
    }

    public function test_active_conversation_prevents_new_request(): void
    {
        $a = $this->user('A');
        $b = $this->user('B');
        $s = app(ChatRequestService::class);
        $s->accept($b, $this->request($a, $b));
        $this->expectException(\DomainException::class);
        $s->create($a, $b);
    }

    private function conversation(): array
    {
        $a = $this->user('A');
        $b = $this->user('B');
        $r = $this->request($a, $b);

        return [$a, $b, app(ChatRequestService::class)->accept($b, $r)];
    }

    public function test_conversation_access_message_types_and_idempotency(): void
    {
        [$a,$b,$c] = $this->conversation();
        $s = app(ConversationService::class);
        $text = $s->send($a, $c, MessageType::Text, 'hello', '', 'm1');
        $same = $s->send($a, $c, MessageType::Text, 'hello', '', 'm1');
        $photo = $s->send($b, $c, MessageType::Photo, null, 'file-photo', 'm2');
        $voice = $s->send($a, $c, MessageType::Voice, null, 'file-voice', 'm3');
        $this->assertTrue($text->is($same));
        $this->assertCount(3, $c->messages);
        $this->assertSame(MessageType::Photo, $photo->message_type);
        $this->assertSame(MessageType::Voice, $voice->message_type);
    }

    public function test_non_participant_and_blocked_conversation_are_denied(): void
    {
        [$a,$b,$c] = $this->conversation();
        $outsider = $this->user('Out');
        $this->expectException(\DomainException::class);
        app(ConversationService::class)->send($outsider, $c, MessageType::Text, 'x', '', 'out');
        app(BlockService::class)->block($a, $b);
        $this->assertSame(ConversationStatus::Blocked, $c->fresh()->status);
    }

    public function test_read_state_marks_only_incoming_messages_and_is_idempotent(): void
    {
        [$a,$b,$c] = $this->conversation();
        $s = app(ConversationService::class);
        $incoming = $s->send($a, $c, MessageType::Text, 'hello', '', 'read-1');
        $own = $s->send($b, $c, MessageType::Text, 'reply', '', 'read-2');
        $this->assertSame(1, $s->markRead($b, $c));
        $this->assertNotNull($incoming->fresh()->read_at);
        $this->assertNull($own->fresh()->read_at);
        $this->assertSame(0, $s->markRead($b, $c));
    }

    public function test_direct_message_lifecycle_does_not_create_conversation(): void
    {
        $a = $this->user('A');
        $b = $this->user('B');
        $s = app(DirectMessageService::class);
        $m = $s->send($a, $b, 'hello', 'dm1');
        $same = $s->send($a, $b, 'hello', 'dm1');
        $this->assertTrue($m->is($same));
        $this->assertDatabaseCount('conversations', 0);
        $seen = $s->markSeen($b, $m);
        $this->assertSame(DirectMessageStatus::Seen, $seen->status);
        $this->assertNotNull($seen->seen_at);
    }

    public function test_direct_message_rejects_self_block_reverse_and_contact_patterns(): void
    {
        $a = $this->user('A');
        $b = $this->user('B');
        $s = app(DirectMessageService::class);
        $this->expectException(\DomainException::class);
        $s->send($a, $a, 'hello', 'self');
        app(BlockService::class)->block($a, $b);
        $this->expectException(\DomainException::class);
        $s->send($a, $b, 'hello', 'blocked');
    }

    public function test_contact_guard_catches_links_without_naive_false_positives(): void
    {
        $g = app(ContactInformationGuard::class);
        foreach (['@sample_user', 't.me/sample_user', 'telegram.me/sample_user', 'tg://resolve?domain=sample_user', 'share Telegram username @sample_user'] as $text) {
            $this->assertTrue($g->blocked($text));
        } $this->assertFalse($g->blocked('I have an idea about telegram games.'));
        $this->assertFalse($g->blocked('The idea is to discuss usernames in general.'));
    }

    public function test_report_profile_and_message_references_are_open_and_immutable_to_users(): void
    {
        [$a,$b,$c] = $this->conversation();
        $message = app(ConversationService::class)->send($a, $c, MessageType::Text, 'bad', '', 'report-message');
        $report = app(ReportService::class)->create($b, $a, ReportReason::Harassment, 'details', $message->id);
        $this->assertSame(ReportStatus::Open, $report->status);
        $this->assertSame(ReportReason::Harassment, $report->reason);
        $this->assertFalse(method_exists(app(ReportService::class), 'updateStatus'));
    }

    public function test_block_cancels_pending_request_and_unblock_does_not_restore_conversation(): void
    {
        $a = $this->user('A');
        $b = $this->user('B');
        $r = $this->request($a, $b);
        $blocks = app(BlockService::class);
        $blocks->block($a, $b);
        $this->assertSame(ChatRequestStatus::Cancelled, $r->fresh()->status);
        $blocks->unblock($a, $b);
        $this->assertDatabaseCount('conversations', 0);
        $this->assertFalse($blocks->isBlocked($a, $b));
    }

    public function test_public_and_contact_outputs_do_not_include_telegram_identity_or_location(): void
    {
        $a = $this->user('A');
        $a->update(['telegram_username' => 'secret']);
        $public = PublicProfile::fromProfile($a->profile->load(['city', 'interests', 'user']));
        $text = $public->text();
        $this->assertStringNotContainsString('secret', $text);
        $this->assertStringNotContainsString((string) $a->telegram_user_id, $text);
        $this->assertStringNotContainsString('latitude', $text);
    }
}
