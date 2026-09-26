<?php

namespace Tests\Feature;

use App\Domain\Profiles\Actions\AdvanceRegistration;
use App\Domain\Profiles\City;
use App\Domain\Profiles\Interest;
use App\Domain\Profiles\Profile;
use App\Domain\Profiles\ProfileStatus;
use App\Domain\Profiles\PublicProfile;
use App\Domain\Profiles\RegistrationInput;
use App\Domain\Profiles\RegistrationState;
use App\Domain\Profiles\RegistrationStep;
use App\Domain\Telegram\Jobs\DeliverMessage;
use App\Domain\Telegram\Jobs\ProcessUpdate;
use App\Domain\Telegram\RegistrationPresenter;
use App\Domain\Telegram\TelegramClient;
use App\Domain\Users\User;
use App\Domain\Users\UserStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\FakeTelegramClient;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    private FakeTelegramClient $telegram;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->seed();
        $this->travelTo(CarbonImmutable::parse('2026-09-17 12:00:00'));
        config(['telegram.webhook_secret' => 'test-secret', 'queue.default' => 'database']);
        Http::preventStrayRequests();
        $this->telegram = new FakeTelegramClient;
        $this->app->instance(TelegramClient::class, $this->telegram);
    }

    private function envelope(array $content = [], bool $callback = false): array
    {
        $id = ++$this->sequence;
        $sender = ['id' => 123456789, 'is_bot' => false, 'first_name' => 'Private', 'username' => 'secret_username'];
        $message = ['message_id' => $id, 'chat' => ['id' => $sender['id'], 'type' => 'private']];

        return ['update_id' => $id] + ($callback ? ['callback_query' => ['id' => 'callback-'.$id, 'from' => $sender, 'message' => $message] + $content] : ['message' => $message + ['from' => $sender] + $content]);
    }

    private function drain(): void
    {
        foreach (['telegram', 'telegram-outbound'] as $queue) {
            while ($job = Queue::connection('database')->pop($queue)) {
                $job->fire();
                $job->delete();
            }
        }
    }

    private function send(string|array $content): void
    {
        $this->postJson('/api/telegram/webhook', $this->envelope(is_string($content) ? ['text' => $content] : $content), ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret'])->assertOk();
        $this->drain();
    }

    private function choose(string $choice, ?int $revision = null): void
    {
        $revision ??= RegistrationState::firstOrFail()->revision;
        $this->postJson('/api/telegram/webhook', $this->envelope(['data' => "r:{$revision}:{$choice}"], true), ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret'])->assertOk();
        $this->drain();
    }

    private function step(): RegistrationStep
    {
        return RegistrationState::firstOrFail()->step;
    }

    private function reach(string $target): void
    {
        $this->send('/start');
        if ($target === 'name') {
            return;
        }
        $this->send('Sara');
        if ($target === 'age') {
            return;
        }
        $this->send('2000-09-17');
        if ($target === 'gender') {
            return;
        }
        $this->choose('female');
        if ($target === 'city') {
            return;
        }
        $this->choose('city_'.City::firstOrFail()->id);
        if ($target === 'photo') {
            return;
        }
        $this->choose('skip');
        if ($target === 'voice') {
            return;
        }
        $this->choose('skip');
        if ($target === 'interests') {
            return;
        }
        $this->choose('skip');
    }

    public function test_start_begins_registration(): void
    {
        $this->send('/start');
        $this->assertSame(RegistrationStep::Name, $this->step());
        $this->assertDatabaseCount('users', 1);
    }

    public function test_active_user_does_not_restart(): void
    {
        $this->reach('preview');
        $this->choose('confirm');
        $completed = Profile::first()->profile_completed_at;
        $this->send('/start');
        $this->assertSame(RegistrationStep::Complete, $this->step());
        $this->assertTrue($completed->equalTo(Profile::first()->profile_completed_at));
    }

    public function test_invalid_names_are_rejected(): void
    {
        $this->reach('name');
        foreach (['x', '1234', '<script>', str_repeat('a', 51), '/unknown'] as $name) {
            $this->send($name);
            $this->assertSame(RegistrationStep::Name, $this->step());
        }
    }

    public function test_unicode_name_is_accepted(): void
    {
        $this->reach('name');
        $this->send('سارا');
        $this->assertSame('سارا', Profile::first()->display_name);
    }

    public function test_underage_is_rejected(): void
    {
        $this->reach('age');
        $this->send('2008-09-18');
        $this->assertSame(RegistrationStep::Age, $this->step());
        $this->assertNull(Profile::first()->birth_date);
    }

    public function test_exactly_eighteen_is_accepted(): void
    {
        $this->reach('age');
        $this->send('2008-09-17');
        $this->assertSame(RegistrationStep::Gender, $this->step());
    }

    public function test_invalid_dates_are_rejected(): void
    {
        $this->reach('age');
        foreach (['17', '2027-01-01', '2000-02-30', '1800-01-01'] as $date) {
            $this->send($date);
            $this->assertSame(RegistrationStep::Age, $this->step());
        }
    }

    public function test_gender_selection(): void
    {
        $this->reach('gender');
        $this->choose('male');
        $this->assertSame('male', Profile::first()->gender->value);
    }

    public function test_gender_requires_button(): void
    {
        $this->reach('gender');
        $this->send('female');
        $this->assertSame(RegistrationStep::Gender, $this->step());
    }

    public function test_city_selection(): void
    {
        $this->reach('city');
        $city = City::first();
        $this->choose('city_'.$city->id);
        $this->assertSame($city->id, Profile::first()->city_id);
    }

    public function test_unknown_city_rejected(): void
    {
        $this->reach('city');
        $this->choose('city_999999');
        $this->assertSame(RegistrationStep::City, $this->step());
    }

    public function test_photo_can_be_skipped(): void
    {
        $this->reach('photo');
        $this->choose('skip');
        $this->assertNull(Profile::first()->photo_file_id);
        $this->assertSame(RegistrationStep::Voice, $this->step());
    }

    public function test_photo_file_id_is_saved(): void
    {
        $this->reach('photo');
        $this->send(['photo' => [['file_id' => 'small'], ['file_id' => 'large']]]);
        $this->assertSame('large', Profile::first()->photo_file_id);
    }

    public function test_voice_can_be_skipped(): void
    {
        $this->reach('voice');
        $this->choose('skip');
        $this->assertNull(Profile::first()->voice_file_id);
        $this->assertSame(RegistrationStep::Interests, $this->step());
    }

    public function test_voice_file_id_and_duration_saved(): void
    {
        $this->reach('voice');
        $this->send(['voice' => ['file_id' => 'voice123', 'duration' => 20]]);
        $this->assertSame('voice123', Profile::first()->voice_file_id);
        $this->assertSame(20, Profile::first()->voice_duration);
    }

    public function test_long_voice_rejected(): void
    {
        $this->reach('voice');
        $this->send(['voice' => ['file_id' => 'voice123', 'duration' => 121]]);
        $this->assertSame(RegistrationStep::Voice, $this->step());
    }

    public function test_interests_can_be_skipped(): void
    {
        $this->reach('interests');
        $this->choose('skip');
        $this->assertSame(RegistrationStep::Preview, $this->step());
        $this->assertDatabaseCount('interest_profile', 0);
    }

    public function test_multiple_interests_can_be_selected(): void
    {
        $this->reach('interests');
        foreach (Interest::limit(2)->get() as $interest) {
            $this->choose('interest_'.$interest->id);
        }
        $this->choose('done');
        $this->assertDatabaseCount('interest_profile', 2);
        $this->assertSame(RegistrationStep::Preview, $this->step());
    }

    public function test_interest_can_be_toggled_off(): void
    {
        $this->reach('interests');
        $id = Interest::first()->id;
        $this->choose('interest_'.$id);
        $this->choose('interest_'.$id);
        $this->assertDatabaseCount('interest_profile', 0);
    }

    public function test_confirmation_activates_profile(): void
    {
        $this->reach('preview');
        $this->choose('confirm');
        $this->assertSame(ProfileStatus::Active, Profile::first()->status);
        $this->assertNotNull(User::first()->registered_at);
        $this->assertNotNull(Profile::first()->profile_completed_at);
    }

    public function test_profile_stays_draft_until_confirmation(): void
    {
        $this->reach('preview');
        $this->assertSame(ProfileStatus::Draft, Profile::first()->status);
        $this->assertNull(User::first()->registered_at);
    }

    public function test_last_activity_tracks_bot_interactions(): void
    {
        $this->send('/start');
        $before = User::first()->last_activity_at;
        $this->travel(5)->minutes();
        $this->send('Sara');
        $this->assertTrue(User::first()->last_activity_at->greaterThan($before));
    }

    public function test_telegram_id_is_unique(): void
    {
        $this->send('/start');
        $this->expectException(QueryException::class);
        User::create(['telegram_user_id' => 123456789]);
    }

    public function test_back_and_edit_before_confirmation(): void
    {
        $this->reach('age');
        $this->choose('back');
        $this->send('Mary');
        $this->assertSame('Mary', Profile::first()->display_name);
        $this->send('2000-01-01');
        $this->choose('female');
        $this->choose('city_'.City::first()->id);
        $this->send('/skip');
        $this->send('/skip');
        $this->send('/skip');
        $this->choose('edit_name');
        $this->assertSame(RegistrationStep::Name, $this->step());
        $this->assertSame(ProfileStatus::Draft, Profile::first()->status);
    }

    public function test_duplicate_update_has_no_duplicate_effects(): void
    {
        $payload = $this->envelope(['text' => '/start']);
        for ($i = 0; $i < 2; $i++) {
            $this->postJson('/api/telegram/webhook', $payload, ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret'])->assertOk();
            $this->drain();
        }
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('telegram_updates', 1);
        $this->assertDatabaseCount('telegram_outbox', 1);
        $this->assertCount(1, $this->telegram->sent);
    }

    public function test_stale_callback_cannot_mutate_profile(): void
    {
        $this->reach('gender');
        $revision = RegistrationState::first()->revision;
        $this->choose('female');
        $this->choose('male', $revision);
        $this->assertSame('female', Profile::first()->gender->value);
        $this->assertSame(RegistrationStep::City, $this->step());
    }

    public function test_invalid_callback_payload_rejected(): void
    {
        $this->postJson('/api/telegram/webhook', $this->envelope(['data' => 'confirm;sql'], true), ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret'])->assertUnprocessable();
        $this->assertDatabaseCount('telegram_updates', 0);
    }

    public function test_wrong_step_confirmation_rejected(): void
    {
        $this->reach('name');
        $this->choose('confirm');
        $this->assertSame(ProfileStatus::Draft, Profile::first()->status);
    }

    public function test_missing_or_wrong_secret_rejected(): void
    {
        $this->postJson('/api/telegram/webhook', $this->envelope(['text' => '/start']))->assertForbidden();
        config(['telegram.webhook_secret' => null]);
        $this->postJson('/api/telegram/webhook', $this->envelope(['text' => '/start']), ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret'])->assertForbidden();
    }

    public function test_group_and_sender_mismatch_rejected(): void
    {
        $p = $this->envelope(['text' => '/start']);
        $p['message']['chat']['type'] = 'group';
        $this->postJson('/api/telegram/webhook', $p, ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret'])->assertUnprocessable();
        $p['message']['chat']['type'] = 'private';
        $p['message']['chat']['id'] = 999;
        $this->postJson('/api/telegram/webhook', $p, ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret'])->assertUnprocessable();
    }

    public function test_unsupported_update_is_acknowledged(): void
    {
        $this->postJson('/api/telegram/webhook', ['update_id' => 100, 'edited_message' => []], ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret'])->assertOk();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_suspended_user_cannot_register(): void
    {
        $this->send('/start');
        User::first()->update(['status' => UserStatus::Suspended]);
        $this->send('Sara');
        $this->assertNull(Profile::first()->display_name);
    }

    public function test_preview_contains_no_private_identity(): void
    {
        $this->reach('preview');
        $text = PublicProfile::fromProfile(Profile::first())->text();
        $this->assertStringNotContainsString('123456789', $text);
        $this->assertStringNotContainsString('secret_username', $text);
        $this->assertStringNotContainsString('2000-09-17', $text);
    }

    public function test_payloads_are_encrypted_and_scrubbed_after_processing(): void
    {
        $p = $this->envelope(['text' => '/start']);
        $this->postJson('/api/telegram/webhook', $p, ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret'])->assertOk();
        $stored = DB::table('telegram_updates')->first()->payload;
        $this->assertStringNotContainsString('secret_username', $stored);
        $this->assertStringContainsString('secret_username', Crypt::decryptString($stored));
        $this->drain();
        $this->assertNull(DB::table('telegram_updates')->first()->payload);
        $this->assertNull(DB::table('telegram_outbox')->first()->payload);
    }

    public function test_job_replay_is_idempotent(): void
    {
        $this->send('/start');
        $this->app->call([new ProcessUpdate(1), 'handle']);
        $this->app->call([new DeliverMessage(DB::table('telegram_outbox')->first()->id), 'handle']);
        $this->assertCount(1, $this->telegram->sent);
    }

    public function test_interest_pivot_prevents_duplicates(): void
    {
        $this->reach('interests');
        $id = Interest::first()->id;
        $this->choose('interest_'.$id);
        $this->expectException(QueryException::class);
        Profile::first()->interests()->attach($id);
    }

    public function test_one_profile_per_user(): void
    {
        $this->send('/start');
        $this->expectException(QueryException::class);
        Profile::create(['user_id' => User::first()->id]);
    }

    public function test_seeders_are_repeatable(): void
    {
        $this->seed();
        $this->assertDatabaseCount('interests', 10);
        $this->assertDatabaseCount('cities', 6);
        $this->assertDatabaseCount('provinces', 4);
    }

    public function test_transport_failure_retries_outbox_without_repeating_domain_changes(): void
    {
        $payload = $this->envelope(['text' => '/start']);
        $this->postJson('/api/telegram/webhook', $payload, ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret'])->assertOk();
        $this->app->call([new ProcessUpdate(1), 'handle']);
        $id = DB::table('telegram_outbox')->first()->id;
        $failing = new class implements TelegramClient
        {
            public function send(string $method, array $parameters): ?array
            {
                throw new \RuntimeException('Unavailable');
            }
        };
        try {
            (new DeliverMessage($id))->handle($failing);
            $this->fail('Expected delivery failure');
        } catch (\RuntimeException $error) {
            $this->assertSame('Unavailable', $error->getMessage());
        }
        $this->assertNull(DB::table('telegram_outbox')->first()->sent_at);
        $this->assertNotNull(DB::table('telegram_updates')->first()->processed_at);
        $revision = RegistrationState::first()->revision;
        $this->app->call([new ProcessUpdate(1), 'handle']);
        (new DeliverMessage($id))->handle($this->telegram);
        $this->assertSame($revision, RegistrationState::first()->revision);
        $this->assertCount(1, $this->telegram->sent);
    }

    public function test_processing_failure_rolls_back_profile_and_outbox(): void
    {
        $payload = $this->envelope(['text' => '/start']);
        $this->postJson('/api/telegram/webhook', $payload, ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret'])->assertOk();
        $action = new class extends AdvanceRegistration
        {
            public function execute(User $user, Profile $profile, RegistrationState $state, RegistrationInput $input): ?string
            {
                $profile->update(['display_name' => 'Should roll back']);
                throw new \RuntimeException('Simulated failure');
            }
        };
        try {
            (new ProcessUpdate(1))->handle($action, app(RegistrationPresenter::class));
            $this->fail('Expected failure');
        } catch (\RuntimeException $error) {
            $this->assertSame('Simulated failure', $error->getMessage());
        }
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('profiles', 0);
        $this->assertDatabaseCount('telegram_outbox', 0);
        $this->assertNull(DB::table('telegram_updates')->first()->processed_at);
        $this->drain();
        $this->assertSame(RegistrationStep::Name, $this->step());
    }

    public function test_pending_updates_process_in_telegram_order(): void
    {
        $first = $this->envelope(['text' => '/start']);
        $second = $this->envelope(['text' => 'Sara']);
        foreach ([$second, $first] as $payload) {
            $this->postJson('/api/telegram/webhook', $payload, ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret'])->assertOk();
        }
        $this->drain();
        $this->assertSame('Sara', Profile::first()->display_name);
        $this->assertSame(RegistrationStep::Age, $this->step());
    }

    public function test_unneeded_private_payload_fields_are_not_stored(): void
    {
        $payload = $this->envelope(['text' => '/start', 'location' => ['latitude' => 35.7, 'longitude' => 51.4], 'contact' => ['phone_number' => 'private-phone']]);
        $this->postJson('/api/telegram/webhook', $payload, ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret'])->assertOk();
        $stored = Crypt::decryptString(DB::table('telegram_updates')->first()->payload);
        $this->assertStringNotContainsString('latitude', $stored);
        $this->assertStringNotContainsString('private-phone', $stored);
    }

    public function test_profile_policy_denies_another_owner(): void
    {
        $this->send('/start');
        $other = User::create(['telegram_user_id' => 987]);
        $this->assertFalse(Gate::forUser($other)->allows('update', Profile::first()));
    }

    public function test_leap_day_does_not_admit_someone_under_eighteen(): void
    {
        $this->travelTo(CarbonImmutable::parse('2028-02-29 12:00:00'));
        $this->reach('age');
        $this->send('2010-03-01');
        $this->assertSame(RegistrationStep::Age, $this->step());
        $this->send('2010-02-28');
        $this->assertSame(RegistrationStep::Gender, $this->step());
    }

    public function test_malformed_nested_payloads_are_rejected_without_server_errors(): void
    {
        $payloads = [
            ['update_id' => 1, 'message' => []],
            ['update_id' => 2, 'callback_query' => []],
            $this->envelope(['text' => '/start']),
        ];
        $payloads[2]['message']['from']['id'] = ['invalid'];
        foreach ($payloads as $payload) {
            $this->postJson('/api/telegram/webhook', $payload, ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret'])->assertUnprocessable();
        }
        $this->assertDatabaseCount('telegram_updates', 0);
    }

    public function test_webhook_validation_returns_json_without_accept_header(): void
    {
        $this->call('POST', '/api/telegram/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN' => 'test-secret',
        ], json_encode(['update_id' => 1, 'message' => []]))
            ->assertUnprocessable()->assertHeader('Content-Type', 'application/json');
    }
}
