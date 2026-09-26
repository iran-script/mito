<?php

namespace Tests\Feature;

use App\Domain\Chat\ConversationService;
use App\Domain\Profiles\RegistrationState;
use App\Domain\Telegram\InteractionState;
use App\Domain\Telegram\InteractionStateResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithGuessGames;
use Tests\TestCase;

class InteractionRecoveryTest extends TestCase
{
    use InteractsWithGuessGames, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootGameTests();
        app()->setLocale('fa');
    }

    public function test_start_escapes_direct_compose_and_profile_edit(): void
    {
        $u = $this->user('Recovery');
        $state = InteractionState::firstOrCreate(['user_id' => $u->id]);
        foreach (['direct_compose', 'profile_edit_name'] as $mode) {
            $state->update(['mode' => $mode, 'direct_context' => ['draft' => 'secret']]);
            $this->telegram->sent = [];
            $this->send($u, '/start', false);
            $this->assertStringContainsString(__('Welcome to Mito. What would you like to do?'), $this->delivered($u));
            $this->assertSame('menu', $state->fresh()->mode);
            $this->assertNull($state->fresh()->direct_context);
        }
    }

    public function test_expired_state_is_cleared_and_valid_active_conversation_wins(): void
    {
        $a = $this->user('A');
        $b = $this->user('B');
        $conversation = app(ConversationService::class)->connectAnonymous($a->id, $b->id);
        $state = InteractionState::updateOrCreate(['user_id' => $a->id], ['mode' => 'direct_review', 'conversation_id' => $conversation->id, 'direct_recipient_id' => $b->id, 'direct_context' => ['draft' => 'old']]);
        DB::table('interaction_states')->where('id', $state->id)->update(['expires_at' => now()->subSecond(), 'updated_at' => now()->subSecond()]);
        $result = app(InteractionStateResolver::class)->resolve($a, $state->fresh());
        $fresh = $state->fresh();
        $this->assertTrue($result['recovered']);
        $this->assertSame('chat', $fresh->mode);
        $this->assertSame($conversation->id, $fresh->conversation_id);
        $this->assertNull($fresh->direct_context);
        $this->assertNotNull($a->fresh()->profile);
    }

    public function test_recovery_is_idempotent_and_does_not_change_wallet_or_profile(): void
    {
        $u = $this->user('Safe');
        $u->wallet()->create(['balance' => 123]);
        $balance = (int) $u->wallet->balance;
        $name = $u->profile->display_name;
        $state = InteractionState::updateOrCreate(['user_id' => $u->id], ['mode' => 'game_guess_number', 'game_context' => ['session_id' => 999999]]);
        $first = app(InteractionStateResolver::class)->resolve($u, $state);
        $second = app(InteractionStateResolver::class)->resolve($u, $state->fresh());
        $this->assertTrue($first['recovered']);
        $this->assertFalse($second['recovered']);
        $this->assertSame($balance, (int) $u->fresh()->wallet->balance);
        $this->assertSame($name, $u->fresh()->profile->display_name);
    }

    public function test_unexpected_failure_rolls_back_partial_changes_before_recovery(): void
    {
        $u = $this->user('Unchanged');
        $u->wallet()->create(['balance' => 123]);
        $failed = false;
        RegistrationState::saving(function () use ($u, &$failed): void {
            if ($failed) {
                return;
            }
            $failed = true;
            $u->profile()->update(['display_name' => 'Partial change']);
            $u->wallet()->update(['balance' => 1]);
            throw new \RuntimeException('Simulated interaction failure');
        });

        $this->send($u, '/start', false);

        $this->assertTrue($failed);
        $this->assertSame('Unchanged', $u->fresh()->profile->display_name);
        $this->assertSame(123, $u->fresh()->wallet->balance);
        $this->assertSame('menu', InteractionState::where('user_id', $u->id)->firstOrFail()->mode);
        $this->assertStringContainsString(__('Something went wrong. I returned you to the safe menu; please try again.'), $this->delivered($u));
        $this->assertNotNull(DB::table('telegram_updates')->first()->processed_at);
    }

    public function test_stale_callback_gets_explicit_persian_response(): void
    {
        $u = $this->user('Callback');
        $this->send($u, 'd:999999:profile');
        $this->assertStringContainsString(__('This option is no longer active.'), $this->delivered($u));
    }
}
