<?php

namespace Tests\Feature;

use App\Domain\Profiles\ProfileStatus;
use App\Domain\Profiles\PublicProfile;
use App\Domain\Profiles\RegistrationState;
use App\Domain\Telegram\DiscoveryInteraction;
use App\Domain\Telegram\IncomingUpdate;
use App\Domain\Telegram\InteractionState;
use App\Domain\Telegram\Keyboard;
use App\Domain\Users\User;
use App\Support\Presentation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithGuessGames;
use Tests\TestCase;

class TelegramRootUxTest extends TestCase
{
    use InteractsWithGuessGames, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootGameTests();
        app()->setLocale('fa');
    }

    private function incomingCallback(User $user, string $action): IncomingUpdate
    {
        return new IncomingUpdate(['update_id' => 900000, 'callback_query' => [
            'id' => 'ux-root', 'data' => 'd:0:'.$action,
            'from' => ['id' => $user->telegram_user_id, 'is_bot' => false, 'first_name' => 'Test'],
            'message' => ['message_id' => 1, 'chat' => ['id' => $user->telegram_user_id, 'type' => 'private']],
        ]]);
    }

    public function test_start_resets_transient_modes_and_shows_balanced_home(): void
    {
        $user = $this->user('Sara');
        $interaction = InteractionState::create(['user_id' => $user->id]);
        DB::table('matchmaking_searches')->insert([
            'user_id' => $user->id, 'gender' => 'female', 'status' => 'waiting', 'generation' => 1,
            'started_at' => now(), 'expires_at' => now()->addMinutes(2), 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (['chat', 'direct', 'event_create', 'game_guess_number', 'menu'] as $mode) {
            $interaction->update([
                'mode' => $mode, 'conversation_id' => null, 'direct_recipient_id' => $user->id,
                'bulk_mode' => 'select', 'bulk_selection' => [$user->id], 'bulk_context' => ['temporary' => true],
                'event_context' => ['step' => 'title'], 'game_context' => ['temporary' => true],
            ]);
            $this->telegram->sent = [];
            $this->send($user, '/start', false);
            $sent = collect($this->telegram->sent)->firstWhere('method', 'sendMessage');
            $this->assertNotNull($sent);
            $this->assertSame(__('Welcome to Mito. What would you like to do?'), $sent['parameters']['text']);
            $this->assertStringNotContainsString(__('No chats yet'), $sent['parameters']['text']);
            $rows = $sent['parameters']['reply_markup']['keyboard'];
            $this->assertTrue($sent['parameters']['reply_markup']['is_persistent']);
            $this->assertCount(4, $rows);
            $this->assertCount(1, $rows[0]);
            $this->assertCount(2, $rows[1]);
            $this->assertCount(2, $rows[2]);
            $this->assertCount(2, $rows[3]);
            $this->assertSame(__('Find people'), $rows[0][0]['text']);
            $this->assertSame('success', $rows[0][0]['style']);
            $this->assertSame([__('Games'), __('Events')], $rows[1]);
            $this->assertSame([__('Contacts'), __('My profile')], $rows[2]);
            $this->assertSame([__('Coins navigation'), __('More')], $rows[3]);
            $this->assertStringNotContainsString(__('Chats'), json_encode($rows, JSON_UNESCAPED_UNICODE));
            $this->assertSame('menu', $interaction->fresh()->mode);
            $this->assertNull($interaction->fresh()->bulk_mode);
            $this->assertNull($interaction->fresh()->event_context);
            $this->assertNull($interaction->fresh()->game_context);
            $this->assertNull($interaction->fresh()->direct_recipient_id);
            $this->assertSame('cancelled', DB::table('matchmaking_searches')->where('user_id', $user->id)->value('status'));
        }
    }

    public function test_incomplete_user_start_continues_registration_and_chats_empty_state_is_intentional(): void
    {
        $user = $this->user('Sara');
        $this->send($user, 's:0:chats');
        $this->assertStringContainsString(__('No active chat right now.'), $this->delivered($user));
        $this->telegram->sent = [];
        $user->profile->update(['status' => ProfileStatus::Draft]);
        RegistrationState::where('user_id', $user->id)->update(['step' => 'name']);
        $this->send($user, '/start', false);
        $this->assertStringContainsString(__('Step :step of 8', ['step' => 1]), $this->delivered($user));
        $this->assertStringNotContainsString(__('No active chat right now.'), $this->delivered($user));
    }

    public function test_profile_card_sends_real_photo_and_voice_with_safe_actions_and_source_back(): void
    {
        $viewer = $this->user('Viewer');
        $target = $this->user('Target');
        $target->profile->update(['photo_file_id' => 'photo-file', 'voice_file_id' => 'voice-file']);
        $state = RegistrationState::where('user_id', $viewer->id)->firstOrFail();
        $messages = app(DiscoveryInteraction::class)->handle($viewer, $this->incomingCallback($viewer, 'profile_'.$target->id.'_page_city_male_2'), $state);
        $this->assertSame(['sendPhoto', 'sendVoice'], array_column($messages, 'method'));
        $this->assertSame('photo-file', $messages[0]['parameters']['photo']);
        $this->assertSame('voice-file', $messages[1]['parameters']['voice']);
        $caption = $messages[0]['parameters']['caption'];
        $this->assertStringContainsString('Target', $caption);
        $this->assertStringContainsString(__('Age'), $caption);
        $this->assertStringContainsString(Presentation::label($target->profile->interests()->firstOrFail()->name), $caption);
        $this->assertStringNotContainsString((string) $target->telegram_user_id, $caption);
        $this->assertStringNotContainsString($target->telegram_username, $caption);
        $rows = $messages[0]['parameters']['reply_markup']['inline_keyboard'];
        $this->assertCount(3, $rows);
        $this->assertSame('d:1:page_city_male_2', $rows[2][1]['callback_data']);
        $this->assertSame('s:1:request_'.$target->id, $rows[0][0]['callback_data']);
        $this->assertSame('d:1:profile_more_'.$target->id, $rows[2][0]['callback_data']);
        foreach ($messages as $message) {
            Keyboard::assertValidPayload($message);
        }
        $target->profile->update(['photo_file_id' => null, 'voice_file_id' => null]);
        $fallback = app(DiscoveryInteraction::class)->handle($viewer, $this->incomingCallback($viewer, 'contact_'.$target->id), $state);
        $this->assertSame(['sendMessage'], array_column($fallback, 'method'));
        $this->assertSame('d:1:contacts', $fallback[0]['parameters']['reply_markup']['inline_keyboard'][2][1]['callback_data']);
    }

    public function test_public_profile_shows_gold_and_approximate_distance_without_private_location(): void
    {
        $profile = new PublicProfile('Sara', 26, 'female', 'Tehran', ['music'], null, null, 3.4, 'active', true);
        $text = $profile->text();
        $this->assertStringContainsString(__('Gold'), $text);
        $this->assertStringContainsString('3.4', $text);
        $this->assertStringNotContainsString('latitude', $text);
        $this->assertStringNotContainsString('longitude', $text);
        $this->assertStringNotContainsString('3.4', (new PublicProfile('Sara', 26, 'female', 'Tehran', []))->text());
    }

    public function test_inline_keyboard_validator_rejects_extra_button_array_layer(): void
    {
        $button = Keyboard::button('d', 1, 'Back', 'menu');
        Keyboard::assertValidPayload(['parameters' => ['reply_markup' => ['inline_keyboard' => [[$button]]]]]);
        $this->expectException(\InvalidArgumentException::class);
        Keyboard::assertValidPayload(['parameters' => ['reply_markup' => ['inline_keyboard' => [[[$button]]]]]]);
    }
}
