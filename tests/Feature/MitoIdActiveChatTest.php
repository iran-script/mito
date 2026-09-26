<?php

namespace Tests\Feature;

use App\Domain\Chat\ChatRequestService;
use App\Domain\Chat\ChatRequestStatus;
use App\Domain\Chat\ConversationService;
use App\Domain\Chat\ConversationStatus;
use App\Domain\Profiles\RegistrationState;
use App\Domain\Telegram\DiscoveryInteraction;
use App\Domain\Telegram\InteractionState;
use App\Domain\Users\MitoId;
use App\Domain\Users\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithGuessGames;
use Tests\TestCase;

class MitoIdActiveChatTest extends TestCase
{
    use InteractsWithGuessGames, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootGameTests();
        app()->setLocale('fa');
    }

    public function test_public_ids_are_generated_unique_normalized_and_database_protected(): void
    {
        $first = $this->user('One');
        $second = $this->user('Two');

        $this->assertMatchesRegularExpression('/^m_[a-z0-9]{8}$/', $first->public_mito_id);
        $this->assertNotSame($first->public_mito_id, $second->public_mito_id);
        $this->assertNotSame((string) $first->telegram_user_id, $first->public_mito_id);
        $this->assertTrue($first->is(User::findByMitoId('/'.strtoupper($first->public_mito_id))));
        $this->assertTrue($first->is(User::findByMitoId($first->public_mito_id)));
        $this->assertSame('/'.$first->public_mito_id, MitoId::display($first->public_mito_id));

        $this->expectException(QueryException::class);
        DB::table('users')->where('id', $second->id)->update(['public_mito_id' => $first->public_mito_id]);
    }

    public function test_lookup_works_during_active_chat_and_is_not_relayed(): void
    {
        $viewer = $this->user('Viewer');
        $partner = $this->user('Partner');
        $target = $this->user('Target');
        $request = app(ChatRequestService::class)->create($viewer, $partner);
        $conversation = app(ChatRequestService::class)->accept($partner, $request);
        InteractionState::create(['user_id' => $viewer->id, 'mode' => 'chat', 'conversation_id' => $conversation->id]);

        $this->send($viewer, '/'.strtoupper($target->public_mito_id), false);

        $visible = json_encode($this->telegram->sent, JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('Target', $visible);
        $this->assertStringContainsString('/'.$target->public_mito_id, $visible);
        $this->assertDatabaseCount('chat_messages', 0);
        $this->assertSame('chat', InteractionState::where('user_id', $viewer->id)->value('mode'));
    }

    public function test_invalid_mito_id_is_safe_and_start_keeps_priority(): void
    {
        $viewer = $this->user('Viewer');
        $this->send($viewer, '/m_missing', false);
        $this->assertStringContainsString(__('No user was found with this Mito ID.'), $this->delivered($viewer));

        $this->telegram->sent = [];
        $this->send($viewer, '/start', false);
        $this->assertStringContainsString(__('Welcome to Mito. What would you like to do?'), $this->delivered($viewer));
    }

    public function test_second_request_and_late_acceptance_are_blocked_without_second_conversation(): void
    {
        $a = $this->user('A');
        $b = $this->user('B');
        $c = $this->user('C');
        $service = app(ChatRequestService::class);
        $ab = $service->create($a, $b);
        $ac = $service->create($a, $c);
        $service->accept($b, $ab);
        $transactions = DB::table('coin_transactions')->count();

        try {
            $service->accept($c, $ac);
            $this->fail('A late second acceptance must be rejected.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('active chat', $exception->getMessage());
        }

        $this->assertSame(ChatRequestStatus::Cancelled, $ac->fresh()->status);
        $this->assertDatabaseCount('conversations', 1);
        $this->assertSame($transactions, DB::table('coin_transactions')->count());

        $this->expectException(\DomainException::class);
        $service->create($a, $c);
    }

    public function test_current_partner_profile_returns_to_chat_and_close_releases_both(): void
    {
        $a = $this->user('A');
        $b = $this->user('B');
        $service = app(ChatRequestService::class);
        $conversation = $service->accept($b, $service->create($a, $b));
        $state = RegistrationState::where('user_id', $a->id)->firstOrFail();
        $messages = app(DiscoveryInteraction::class)->lookupProfile($a, $b->public_mito_id, $state);
        $buttons = $messages[0]['parameters']['reply_markup']['inline_keyboard'];
        $this->assertSame('s:1:open_'.$conversation->id, $buttons[0][0]['callback_data']);

        app(ConversationService::class)->close($a, $conversation);
        $this->assertSame(ConversationStatus::Closed, $conversation->fresh()->status);
        $this->assertNull(app(ConversationService::class)->activeFor($a));
        $this->assertNotNull($service->create($a, $this->user('C')));
    }
}
