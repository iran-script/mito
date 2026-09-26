<?php

namespace Tests\Feature;

use App\Domain\Chat\ChatRequestService;
use App\Domain\Chat\ConversationService;
use App\Domain\Chat\MessageType;
use App\Domain\Direct\DirectMessageService;
use App\Domain\Games\GameService;
use App\Domain\Games\GameType;
use App\Domain\Payments\CoinTransactionType;
use App\Domain\Payments\FeaturePricingService;
use App\Domain\Payments\PaidFeature;
use App\Domain\Payments\WalletService;
use App\Domain\Telegram\InteractionState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithGuessGames;
use Tests\TestCase;

class FocusedPersianEconomyUxTest extends TestCase
{
    use InteractsWithGuessGames, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootGameTests();
        app()->setLocale('fa');
    }

    public function test_direct_costs_two_and_read_reply_compose_can_cancel_without_charge(): void
    {
        $sender = $this->user('فرستنده');
        $recipient = $this->user('گیرنده');
        app(WalletService::class)->wallet($sender)->update(['balance' => 10]);
        app(WalletService::class)->wallet($recipient)->update(['balance' => 10]);

        $message = app(DirectMessageService::class)->send($sender, $recipient, 'سلام', 'direct-price-two');
        $this->assertSame(2, app(FeaturePricingService::class)->cost(PaidFeature::DirectMessage));
        $this->assertSame(8, $sender->wallet->fresh()->balance);
        $this->assertSame(10, $recipient->wallet->fresh()->balance);

        $this->send($recipient, 'n:0:open_direct_'.$message->id);
        $state = InteractionState::where('user_id', $recipient->id)->firstOrFail();
        $visible = json_encode($this->telegram->sent, JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString(__('Reply'), $visible);
        $this->assertStringContainsString(__('View sender profile'), $visible);
        $this->assertNotNull($message->fresh()->seen_at);

        $this->send($recipient, 's:'.$state->revision.':direct_reply_'.$message->id);
        $state->refresh();
        $this->assertSame('direct_compose', $state->mode);
        $this->assertSame($sender->id, $state->direct_recipient_id);
        $this->assertStringContainsString(__('Cancel'), json_encode($this->telegram->sent, JSON_UNESCAPED_UNICODE));
        $this->send($recipient, 'پاسخ آزمایشی', false);
        $state->refresh();
        $this->assertSame('direct_review', $state->mode);
        $this->assertStringContainsString(__('Send'), json_encode($this->telegram->sent, JSON_UNESCAPED_UNICODE));
        $this->assertStringContainsString(__('Edit'), json_encode($this->telegram->sent, JSON_UNESCAPED_UNICODE));
        $this->assertStringContainsString(__('Direct send cost', ['coins' => '۲']), json_encode($this->telegram->sent, JSON_UNESCAPED_UNICODE));
        $before = $recipient->wallet->fresh()->balance;
        $this->send($recipient, 's:'.$state->revision.':direct_cancel');
        $this->assertSame($before, $recipient->wallet->fresh()->balance);
        $this->assertDatabaseCount('direct_messages', 1);
        $this->assertNull(InteractionState::where('user_id', $recipient->id)->value('direct_context'));
    }

    public function test_manual_chat_rewards_accepter_once_on_tenth_combined_message(): void
    {
        $requester = $this->user('درخواست‌دهنده');
        $accepter = $this->user('پذیرنده');
        app(WalletService::class)->wallet($requester)->update(['balance' => 10]);
        app(WalletService::class)->wallet($accepter)->update(['balance' => 10]);
        $request = app(ChatRequestService::class)->create($requester, $accepter);
        $conversation = app(ChatRequestService::class)->accept($accepter, $request);

        $this->assertSame('manual_request', $conversation->origin);
        $this->assertSame(8, $requester->wallet->fresh()->balance);
        $this->assertSame(10, $accepter->wallet->fresh()->balance);
        foreach (range(1, 9) as $number) {
            $sender = $number % 2 ? $requester : $accepter;
            app(ConversationService::class)->send($sender, $conversation, MessageType::Text, 'پیام '.$number, null, 'reward-'.$number);
        }
        $this->assertSame(10, $accepter->wallet->fresh()->balance);
        app(ConversationService::class)->send($accepter, $conversation, MessageType::Text, 'پیام ۱۰', null, 'reward-10');
        $this->assertSame(11, $accepter->wallet->fresh()->balance);
        $this->assertSame(8, $requester->wallet->fresh()->balance);
        $this->assertDatabaseHas('coin_transactions', [
            'user_id' => $accepter->id,
            'type' => CoinTransactionType::ChatEngagementReward->value,
            'code' => 'chat_engagement_reward',
            'amount' => 1,
            'idempotency_key' => 'chat_engagement_reward:'.$request->id,
        ]);
        $metadata = DB::table('coin_transactions')->where('code', 'chat_engagement_reward')->value('metadata');
        $this->assertSame($conversation->id, json_decode($metadata, true)['conversation_id']);
        app(ConversationService::class)->send($requester, $conversation, MessageType::Text, 'پیام ۱۱', null, 'reward-11');
        $this->assertSame(11, $accepter->wallet->fresh()->balance);
        $this->assertSame(1, DB::table('coin_transactions')->where('code', 'chat_engagement_reward')->count());
        $this->assertDatabaseHas('social_outbox', ['source_type' => 'chat_engagement_reward', 'source_id' => $conversation->id]);
    }

    public function test_anonymous_conversation_never_receives_engagement_reward(): void
    {
        $a = $this->user('الف');
        $b = $this->user('ب');
        app(WalletService::class)->wallet($a)->update(['balance' => 10]);
        app(WalletService::class)->wallet($b)->update(['balance' => 10]);
        $conversation = app(ConversationService::class)->connectAnonymous($a->id, $b->id);
        foreach (range(1, 10) as $number) {
            app(ConversationService::class)->send($number % 2 ? $a : $b, $conversation, MessageType::Text, 'پیام '.$number, null, 'anonymous-'.$number);
        }
        $this->assertSame(10, $a->wallet->fresh()->balance);
        $this->assertSame(10, $b->wallet->fresh()->balance);
        $this->assertSame(0, DB::table('coin_transactions')->where('code', 'chat_engagement_reward')->count());
    }

    public function test_anonymous_copy_and_visible_games_are_persian_and_limited(): void
    {
        foreach ([__('Who are you looking for?'), __('Male'), __('Female'), __('Looking for someone for you... Up to two minutes.'), __("Someone's here!\nChat connected.\n\nYou can send a message right here.")] as $text) {
            $this->assertDoesNotMatchRegularExpression('/[A-Za-z]{3,}/', $text);
        }

        $user = $this->user('بازیکن');
        $this->send($user, __('Games'), false);
        $visible = json_encode($this->telegram->sent, JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString(__('RPS'), $visible);
        $this->assertStringContainsString(__('Truth or Dare'), $visible);
        foreach ([__('Speed Quiz'), __('This or That'), __('Guess Interest'), __('Guess Number')] as $hidden) {
            $this->assertStringNotContainsString($hidden, $visible);
        }
        $other = $this->user('حریف');
        $session = app(GameService::class)->invite($user, $other, GameType::TruthOrDare);
        app(GameService::class)->accept($other, $session);
        $this->assertSame(0, DB::table('coin_transactions')->count());
        $this->send($other, 'd:0:game_open_'.$session->id);
        $game = json_encode($this->telegram->sent, JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString(__('Truth'), $game);
        $this->assertStringContainsString(__('Dare'), $game);
    }
}
