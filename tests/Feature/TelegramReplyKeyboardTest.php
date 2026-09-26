<?php

namespace Tests\Feature;

use App\Domain\Profiles\City;
use App\Domain\Profiles\ProfileStatus;
use App\Domain\Profiles\Province;
use App\Domain\Telegram\InteractionState;
use App\Domain\Telegram\Keyboard;
use App\Domain\Users\User;
use App\Support\Presentation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithGuessGames;
use Tests\TestCase;

class TelegramReplyKeyboardTest extends TestCase
{
    use InteractsWithGuessGames, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootGameTests();
        app()->setLocale('fa');
    }

    private function latest(): array
    {
        return collect($this->telegram->sent)->filter(fn ($message) => $message['method'] === 'sendMessage')->last()['parameters'];
    }

    public function test_registration_uses_reply_grids_from_age_through_confirmation(): void
    {
        $user = User::create(['telegram_user_id' => random_int(100000000, 999999999)]);
        $this->send($user, '/start', false);
        $this->send($user, 'سارا', false);
        $this->assertSame(['18', '19', '20', '21', '22', '23'], $this->latest()['reply_markup']['keyboard'][0]);
        $this->send($user, '18', false);
        $this->assertSame([__('Man'), __('Woman')], $this->latest()['reply_markup']['keyboard'][0]);
        $this->send($user, __('Woman'), false);
        $this->assertCount(3, $this->latest()['reply_markup']['keyboard'][0]);
        $province = Province::orderBy('name')->firstOrFail();
        $this->send($user, Presentation::label($province->name), false);
        $this->assertGreaterThanOrEqual(1, count($this->latest()['reply_markup']['keyboard'][0]));
        $city = City::where('province_id', $province->id)->orderBy('name')->firstOrFail();
        $this->send($user, Presentation::label($city->name), false);
        $this->assertSame([__('Skip'), __('Back')], $this->latest()['reply_markup']['keyboard'][0]);
        $this->send($user, __('Skip'), false);
        $this->send($user, __('Skip'), false);
        $this->assertContains(__('Confirm interests'), array_merge(...$this->latest()['reply_markup']['keyboard']));
        $this->send($user, __('Skip'), false);
        $this->assertSame([__('Confirm')], $this->latest()['reply_markup']['keyboard'][0]);
        $this->send($user, __('Confirm'), false);
        $this->assertSame(ProfileStatus::Active, $user->fresh()->profile->status);
        $this->assertSame($city->id, $user->fresh()->profile->city_id);
        $this->assertSame(18, $user->fresh()->profile->birth_date->age);
        $this->assertCount(4, $this->latest()['reply_markup']['keyboard']);
    }

    public function test_home_and_search_reply_buttons_route_without_callback_changes(): void
    {
        $viewer = $this->user('Viewer');
        $target = $this->user('Target');
        $this->send($viewer, '/start', false);
        $this->assertTrue($this->latest()['reply_markup']['is_persistent']);
        $this->send($viewer, __('Find people'), false);
        $rootButtons = array_merge(...$this->latest()['reply_markup']['keyboard']);
        $this->assertNotContains(__('Search women'), $rootButtons);
        $this->assertNotContains(__('Search men'), $rootButtons);
        $this->send($viewer, __('Same city'), false);
        $genderRows = $this->latest()['reply_markup']['inline_keyboard'];
        $this->assertSame([__('Male'), __('Female')], array_column($genderRows[0], 'text'));
        $this->send($viewer, $genderRows[0][0]['callback_data']);
        $rows = $this->latest()['reply_markup']['inline_keyboard'];
        $this->assertLessThanOrEqual(7, count($rows));
        $this->assertTrue(collect($rows)->flatten(1)->contains(fn ($button) => str_contains($button['callback_data'], 'profile_'.$target->id)));
    }

    public function test_event_day_time_and_capacity_reply_buttons_follow_existing_wizard(): void
    {
        $user = $this->user('Host');
        $this->send($user, 'd:0:event_create');
        $this->send($user, 'A friendly picnic', false);
        $categoryButton = $this->latest()['reply_markup']['inline_keyboard'][0][0]['callback_data'];
        $this->send($user, $categoryButton);
        $this->send($user, '/skip', false);
        $day = $this->latest();
        $this->assertContains(__('Tomorrow'), array_merge(...$day['reply_markup']['keyboard']));
        $this->assertStringNotContainsString('YYYY-MM-DD', $day['text']);
        $this->send($user, __('Tomorrow'), false);
        $this->assertSame(['08:00', '10:00', '12:00'], $this->latest()['reply_markup']['keyboard'][0]);
        $this->send($user, __('Back'), false);
        $this->assertContains(__('Tomorrow'), array_merge(...$this->latest()['reply_markup']['keyboard']));
        $this->send($user, __('Tomorrow'), false);
        $this->send($user, '22:00', false);
        $this->assertContains(__('Unlimited'), array_merge(...$this->latest()['reply_markup']['keyboard']));
        $this->send($user, __(':count people', ['count' => 4]), false);
        $this->assertSame('location', InteractionState::where('user_id', $user->id)->firstOrFail()->event_context['step']);
    }

    public function test_back_from_direct_compose_does_not_send_or_charge(): void
    {
        $user = $this->user('Sender');
        $recipient = $this->user('Recipient');
        InteractionState::create(['user_id' => $user->id, 'mode' => 'direct', 'direct_recipient_id' => $recipient->id]);
        $this->send($user, __('Back'), false);
        $this->assertDatabaseCount('direct_messages', 0);
        $this->assertSame('menu', InteractionState::where('user_id', $user->id)->value('mode'));
        $this->assertNull(InteractionState::where('user_id', $user->id)->value('direct_recipient_id'));
        $this->send($user, __('Find people'), false);
        $this->assertDatabaseCount('direct_messages', 0);
        $this->assertArrayHasKey('keyboard', $this->latest()['reply_markup']);
    }

    public function test_event_weekday_resolves_to_next_matching_day_and_other_date_is_button_driven(): void
    {
        $today = CarbonImmutable::today(config('presentation.timezone'));
        $next = $today->addDays(3);
        $label = $next->locale('fa')->translatedFormat('l');
        $action = Keyboard::eventAction($label, ['step' => 'day', 'day_page' => 0]);
        $this->assertSame('event_day_'.$next->format('Ymd'), $action);
        $this->assertSame('event_day_page_1', Keyboard::eventAction(__('Other date'), ['step' => 'day', 'day_page' => 0]));
        $page = Keyboard::eventDaysReply(1);
        $this->assertContains(__('Previous'), array_merge(...$page));
        $this->assertContains(__('Next'), array_merge(...$page));
        $this->assertSame('event_day_page_0', Keyboard::eventAction(__('Previous'), ['step' => 'day', 'day_page' => 1]));
    }

    public function test_reply_keyboard_payload_validator_rejects_nested_rows(): void
    {
        Keyboard::assertValidPayload(['parameters' => ['reply_markup' => Keyboard::reply(Keyboard::homeReply(), true)]]);
        $this->expectException(\InvalidArgumentException::class);
        Keyboard::assertValidPayload(['parameters' => ['reply_markup' => Keyboard::reply([[['nested']]])]]);
    }
}
