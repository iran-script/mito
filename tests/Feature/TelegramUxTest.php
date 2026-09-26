<?php

namespace Tests\Feature;

use App\Domain\Profiles\City;
use App\Domain\Profiles\Gender;
use App\Domain\Profiles\Profile;
use App\Domain\Profiles\ProfileStatus;
use App\Domain\Profiles\RegistrationState;
use App\Domain\Telegram\DiscoveryInteraction;
use App\Domain\Telegram\IncomingUpdate;
use App\Domain\Telegram\Jobs\MatchmakingPoll;
use App\Domain\Telegram\RegistrationPresenter;
use App\Domain\Users\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TelegramUxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        app()->setLocale('fa');
    }

    private function person(): array
    {
        $user = User::create(['telegram_user_id' => random_int(10000000, 99999999), 'last_activity_at' => now()]);
        Profile::create(['user_id' => $user->id, 'display_name' => 'ط·آ³ط·آ§ط·آ±ط·آ§', 'birth_date' => '2000-01-01', 'gender' => Gender::Female, 'city_id' => City::firstOrFail()->id, 'status' => ProfileStatus::Active, 'profile_completed_at' => now()]);
        $state = RegistrationState::create(['user_id' => $user->id, 'step' => 'complete']);

        return [$user->fresh('profile'), $state];
    }

    private function incomingCallback(User $user, string $action): IncomingUpdate
    {
        return new IncomingUpdate(['update_id' => random_int(1, 999999), 'callback_query' => [
            'id' => 'ux', 'from' => ['id' => $user->telegram_user_id, 'is_bot' => false, 'first_name' => 'User'],
            'message' => ['message_id' => 1, 'chat' => ['id' => $user->telegram_user_id, 'type' => 'private']],
            'data' => 'd:0:'.$action,
        ]]);
    }

    private function buttons(array $messages): array
    {
        return array_merge(...$messages[0]['parameters']['reply_markup']['inline_keyboard']);
    }

    public function test_main_and_more_menus_keep_clear_action_hierarchy(): void
    {
        [$user, $state] = $this->person();
        $interaction = app(DiscoveryInteraction::class);
        $main = $interaction->menu($state);
        $rows = $main[0]['parameters']['reply_markup']['keyboard'];
        $this->assertCount(4, $rows);
        $this->assertSame([['text' => __('Find people'), 'style' => 'success']], $rows[0]);
        $this->assertStringNotContainsString(__('Chats'), json_encode($rows, JSON_UNESCAPED_UNICODE));
        $this->assertSame([__('Coins navigation'), __('More')], $rows[3]);
        $this->assertTrue($main[0]['parameters']['reply_markup']['is_persistent']);
        $more = $interaction->handle($user, $this->incomingCallback($user, 'more'), $state);
        $this->assertCount(6, $this->buttons($more));
        $moreButtons = $this->buttons($more);
        $this->assertSame('d:1:menu', end($moreButtons)['callback_data']);
    }

    public function test_registration_age_is_paginated_and_never_requests_gregorian_date(): void
    {
        [$user, $state] = $this->person();
        $user->profile->update(['status' => ProfileStatus::Draft]);
        $state->update(['step' => 'age']);
        $message = app(RegistrationPresenter::class)->messages($user->profile->fresh(), $state->fresh(), null);
        $this->assertStringContainsString(__('Step :step of 8', ['step' => 2]), $message[0]['parameters']['text']);
        $this->assertStringNotContainsString('YYYY-MM-DD', $message[0]['parameters']['text']);
        $rows = $message[0]['parameters']['reply_markup']['keyboard'];
        $this->assertSame(['18', '19', '20', '21', '22', '23'], $rows[0]);
        $this->assertSame(['30', '31', '32', '33', '34', '35'], $rows[2]);
        $this->assertSame([__('Next')], $rows[3]);
    }

    public function test_anonymous_search_waits_can_cancel_and_times_out(): void
    {
        [$user, $state] = $this->person();
        $interaction = app(DiscoveryInteraction::class);
        $start = $interaction->handle($user, $this->incomingCallback($user, 'anonymous_male'), $state);
        $this->assertSame(__('Looking for someone for you... Up to two minutes.'), $start[0]['parameters']['text']);
        $this->assertStringNotContainsString(__('Nobody matched this search yet.'), $start[0]['parameters']['text']);
        $this->assertSame('waiting', DB::table('matchmaking_searches')->where('user_id', $user->id)->value('status'));
        $this->assertSame('d:1:match_cancel', $this->buttons($start)[0]['callback_data']);
        $interaction->handle($user, $this->incomingCallback($user, 'match_cancel'), $state);
        $this->assertSame('cancelled', DB::table('matchmaking_searches')->where('user_id', $user->id)->value('status'));
        $interaction->handle($user, $this->incomingCallback($user, 'anonymous_male'), $state);
        $generation = DB::table('matchmaking_searches')->where('user_id', $user->id)->value('generation');
        $this->travel(3)->minutes();
        app()->call([new MatchmakingPoll($user->id, $generation), 'handle']);
        $this->assertSame('timed_out', DB::table('matchmaking_searches')->where('user_id', $user->id)->value('status'));
    }

    public function test_event_wizard_offers_day_time_and_capacity_buttons(): void
    {
        [$user, $state] = $this->person();
        $interaction = app(DiscoveryInteraction::class);
        $title = $interaction->handle($user, $this->incomingCallback($user, 'event_create'), $state);
        $this->assertStringContainsString(__('Event step 1 of 8'), $title[0]['parameters']['text']);
        $this->assertSame('d:1:events', $this->buttons($title)[0]['callback_data']);
    }
}
