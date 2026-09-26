<?php

namespace Tests\Feature;

use App\Domain\Chat\ChatRequest;
use App\Domain\Chat\Conversation;
use App\Domain\Chat\ConversationCleanupService;
use App\Domain\Payments\WalletService;
use App\Domain\Telegram\InteractionState;
use App\Domain\Telegram\Keyboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithGuessGames;
use Tests\TestCase;

class FinalTelegramUxCorrectionTest extends TestCase
{
    use InteractsWithGuessGames, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootGameTests();
        app()->setLocale('fa');
    }

    private function connect(): array
    {
        $a = $this->user('A');
        $b = $this->user('B');
        app(WalletService::class)->wallet($a)->update(['balance' => 100]);
        $this->send($a, 's:0:request_'.$b->id);
        $request = ChatRequest::sole();
        $this->send($b, 'n:0:view_request_'.$request->id);
        $state = InteractionState::where('user_id', $b->id)->firstOrFail();
        $this->send($b, 's:'.$state->revision.':accept_request_'.$request->id);
        $this->telegram->sent = [];

        return [$a, $b, Conversation::sole()];
    }

    public function test_active_chat_keyboard_has_only_required_rows_and_normal_chat_has_no_ack(): void
    {
        [$a, $b] = $this->connect();
        $this->assertCount(3, Keyboard::chatReply(false));
        $this->assertStringNotContainsString(__('Return to chat'), json_encode(Keyboard::chatReply(false), JSON_UNESCAPED_UNICODE));
        $this->send($a, 'سلام', false);
        $toA = collect($this->telegram->sent)->filter(fn ($m) => ($m['parameters']['chat_id'] ?? null) === $a->telegram_user_id);
        $toB = collect($this->telegram->sent)->filter(fn ($m) => ($m['parameters']['chat_id'] ?? null) === $b->telegram_user_id);
        $this->assertCount(0, $toA);
        $this->assertTrue($toB->contains(fn ($m) => ($m['parameters']['text'] ?? null) === 'سلام'));
        $this->assertStringNotContainsString(__('Message sent.'), json_encode($this->telegram->sent, JSON_UNESCAPED_UNICODE));
        $this->assertDatabaseHas('conversation_telegram_messages', ['user_id' => $a->id, 'direction' => 'incoming']);
        $this->assertDatabaseHas('conversation_telegram_messages', ['user_id' => $b->id, 'direction' => 'outgoing']);
    }

    public function test_end_notifies_both_and_cleanup_is_two_sided_and_idempotent(): void
    {
        [$a, $b, $conversation] = $this->connect();
        $this->send($a, 'پیام آزمایشی', false);
        $this->telegram->sent = [];
        $this->send($a, __('End chat'), false);
        $state = InteractionState::where('user_id', $a->id)->firstOrFail();
        $this->send($a, 's:'.$state->revision.':confirm_close_chat_'.$conversation->id);
        $this->assertStringContainsString($b->public_mito_id, $this->delivered($a));
        $this->assertStringContainsString($a->public_mito_id, $this->delivered($b));
        foreach ([$a, $b] as $user) {
            $sent = collect($this->telegram->sent)->filter(fn ($m) => ($m['parameters']['chat_id'] ?? null) === $user->telegram_user_id);
            $this->assertTrue($sent->contains(fn ($m) => str_contains(json_encode($m, JSON_UNESCAPED_UNICODE), __('Delete this chat messages'))));
            $this->assertTrue($sent->contains(fn ($m) => ($m['parameters']['reply_markup']['keyboard'] ?? null) === Keyboard::homeReply()));
        }
        $this->send($a, 'n:0:cleanup_chat_'.$conversation->id);
        $this->assertSame('completed', $conversation->fresh()->telegram_cleanup_status);
        $this->assertSame(0, DB::table('conversation_telegram_messages')->where('conversation_id', $conversation->id)->where('delete_status', 'pending')->count());
        $this->assertDatabaseCount('chat_messages', 1);
        $this->assertSame('completed', app(ConversationCleanupService::class)->request($a, $conversation->fresh()));
        $third = $this->user('Third');
        $this->expectException(\DomainException::class);
        app(ConversationCleanupService::class)->request($third, $conversation->fresh());
    }

    public function test_my_profile_uses_media_and_independent_age_and_photo_edits(): void
    {
        $user = $this->user('Owner');
        $user->profile->update(['photo_file_id' => 'my-photo', 'voice_file_id' => 'my-voice', 'voice_duration' => 4]);
        $this->send($user, __('My profile'), false);
        $this->assertTrue(collect($this->telegram->sent)->contains(fn ($m) => $m['method'] === 'sendPhoto' && ($m['parameters']['photo'] ?? null) === 'my-photo'));
        $this->assertTrue(collect($this->telegram->sent)->contains(fn ($m) => $m['method'] === 'sendVoice' && ($m['parameters']['voice'] ?? null) === 'my-voice'));
        $this->assertStringContainsString($user->public_mito_id, json_encode($this->telegram->sent, JSON_UNESCAPED_UNICODE));
        $interaction = InteractionState::where('user_id', $user->id)->firstOrFail();
        $this->send($user, 'd:'.$interaction->revision.':profile_edit_age');
        $this->send($user, '30', false);
        $this->assertSame(30, $user->profile->fresh()->birth_date->age);
        $interaction->refresh();
        $this->send($user, 'd:'.$interaction->revision.':profile_edit_photo');
        $this->send($user, __('Delete current photo'), false);
        $this->assertNull($user->profile->fresh()->photo_file_id);
    }

    public function test_direct_presentation_strings_are_persian(): void
    {
        foreach ([__('Type your direct message.'), __('Type the revised message.'), __('Send'), __('Edit'), __('You have a new direct message.'), __('View message'), __('Direct message from:'), __('Your message to :mito_id was read.', ['mito_id' => '/m_test']), __('Direct message sent.')] as $text) {
            $this->assertDoesNotMatchRegularExpression('/[A-Za-z]{3,}/', str_replace('/m_test', '', $text));
        }
    }

    public function test_home_has_no_chats_and_find_people_is_solo_success_button(): void
    {
        $rows = Keyboard::homeReply();
        $this->assertSame([['text' => __('Find people'), 'style' => 'success']], $rows[0]);
        $this->assertStringNotContainsString(__('Chats'), json_encode($rows, JSON_UNESCAPED_UNICODE));
        $this->assertCount(4, $rows);
    }
}
