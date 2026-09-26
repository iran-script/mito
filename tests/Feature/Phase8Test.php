<?php

namespace Tests\Feature;

use App\Domain\Admin\AdminAuditLog;
use App\Domain\Admin\AdminAuthorization;
use App\Domain\Admin\AdminUser;
use App\Domain\Admin\FinanceService;
use App\Domain\Admin\GameOperationsService;
use App\Domain\Admin\ModerationService;
use App\Domain\Admin\OperationsMetrics;
use App\Domain\Admin\ReportInspection;
use App\Domain\Chat\ChatRequestService;
use App\Domain\Chat\ConversationService;
use App\Domain\Chat\MessageType;
use App\Domain\Direct\DirectMessageService;
use App\Domain\Discovery\DiscoveryService;
use App\Domain\Events\EventCategory;
use App\Domain\Events\EventReportReason;
use App\Domain\Events\EventService;
use App\Domain\Games\DailyChallengeService;
use App\Domain\Games\GameService;
use App\Domain\Games\GameType;
use App\Domain\Memberships\BulkChatRequestService;
use App\Domain\Memberships\BulkDirectMessageService;
use App\Domain\Memberships\GoldLimitsService;
use App\Domain\Memberships\GoldMembershipService;
use App\Domain\Moderation\ReportReason;
use App\Domain\Moderation\ReportService;
use App\Domain\Moderation\ReportStatus;
use App\Domain\Moderation\RestrictionService;
use App\Domain\Payments\CoinPackage;
use App\Domain\Payments\CoinTransaction;
use App\Domain\Payments\IdentityShareService;
use App\Domain\Payments\InsufficientCoinsException;
use App\Domain\Payments\PaidFeature;
use App\Domain\Payments\WalletService;
use App\Domain\Profiles\City;
use App\Domain\Profiles\Gender;
use App\Domain\Profiles\Profile;
use App\Domain\Profiles\ProfileStatus;
use App\Domain\Profiles\RegistrationState;
use App\Domain\Telegram\Actions\AcceptUpdate;
use App\Domain\Telegram\InteractionState;
use App\Domain\Telegram\Jobs\ProcessUpdate;
use App\Domain\Users\User;
use App\Domain\Users\UserStatus;
use App\Filament\Auth\Login;
use App\Filament\Pages\EconomySettings;
use App\Filament\Pages\GameControls;
use App\Filament\Resources\BlockResource;
use App\Filament\Resources\GameSessionResource;
use App\Filament\Resources\Pages\ManageCoinPackage;
use App\Filament\Resources\Pages\ManageEvent;
use App\Filament\Resources\Pages\ManageGameSession;
use App\Filament\Resources\Pages\ManageGoldMembership;
use App\Filament\Resources\Pages\ManageQuiz;
use App\Filament\Resources\Pages\ManageReport;
use App\Filament\Resources\Pages\ManageTransaction;
use App\Filament\Resources\Pages\ManageUser;
use App\Filament\Resources\Pages\ManageWallet;
use App\Filament\Resources\TransactionResource;
use App\Filament\Resources\WalletResource;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class Phase8Test extends TestCase
{
    use RefreshDatabase;

    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->seed();
        DB::table('coin_feature_prices')->where('feature_code', 'game_invitation')->update(['coin_cost' => 0]);
        $this->admin = $this->admin();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function admin(string $role = 'super_admin', bool $active = true): AdminUser
    {
        return AdminUser::create(['name' => 'Operator', 'email' => uniqid().'@example.test', 'password' => 'Strong-test-pass-123', 'is_active' => $active, 'role' => $role]);
    }

    private function user(): User
    {
        $u = User::create(['telegram_user_id' => random_int(10000000, 99999999), 'last_activity_at' => now()]);
        Profile::create(['user_id' => $u->id, 'display_name' => 'Player '.$u->id, 'birth_date' => '2000-01-01', 'gender' => Gender::Male, 'city_id' => City::first()->id, 'status' => ProfileStatus::Active, 'profile_completed_at' => now()]);

        return $u->fresh(['profile']);
    }

    private function rejects(callable $fn, string $class = \DomainException::class): void
    {
        try {
            $fn();
            $this->fail('Expected rejection');
        } catch (\Throwable $e) {
            $this->assertInstanceOf($class, $e);
        }
    }

    private function event(User $u)
    {
        return app(EventService::class)->create($u, ['title' => 'Safe event', 'category_id' => EventCategory::first()->id, 'starts_at' => now()->addDay()]);
    }

    public static function routes(): array
    {
        return array_map(fn ($p) => [$p], ['', 'users', 'reports', 'event-reports', 'blocks', 'restrictions', 'wallets', 'transactions', 'feature-pricings', 'coin-packages', 'purchase-orders', 'gold-memberships', 'events', 'game-sessions', 'quizzes', 'this-or-thats', 'audit-logs', 'badges', 'economy-settings', 'gold-settings', 'game-controls']);
    }

    #[DataProvider('routes')]
    public function test_admin_routes_require_auth_and_render_for_active_admin(string $route): void
    {
        $this->get('/admin'.($route ? '/'.$route : ''))->assertRedirect('/admin/login');
        $this->actingAs($this->admin, 'admin')->get('/admin'.($route ? '/'.$route : ''))->assertOk();
    }

    public function test_normal_web_user_cannot_access_admin(): void
    {
        $u = $this->user();
        $this->assertNotInstanceOf(Authenticatable::class, $u);
        $this->actingAs(new GenericUser(['id' => $u->id]), 'web')->get('/admin')->assertRedirect('/admin/login');
        $this->assertFalse(app(AdminAuthorization::class)->allows(null, 'moderation'));
    }

    public function test_active_admin_login_uses_hashed_password(): void
    {
        $this->assertTrue(Hash::check('Strong-test-pass-123', $this->admin->password));
        $this->assertStringNotContainsString('password', $this->admin->toJson());
        Livewire::test(Login::class)->fillForm(['email' => $this->admin->email, 'password' => 'Strong-test-pass-123'])->call('authenticate')->assertHasNoFormErrors();
        $this->assertAuthenticatedAs($this->admin, 'admin');
    }

    public function test_inactive_admin_cannot_login(): void
    {
        $a = $this->admin('super_admin', false);
        Livewire::test(Login::class)->fillForm(['email' => $a->email, 'password' => 'Strong-test-pass-123'])->call('authenticate')->assertHasFormErrors();
        $this->assertGuest('admin');
    }

    public function test_inactive_existing_session_denied(): void
    {
        $this->actingAs($this->admin, 'admin');
        $this->admin->update(['is_active' => false]);
        $this->get('/admin')->assertForbidden();
    }

    public function test_admin_create_is_cli_only_and_password_is_hashed(): void
    {
        $this->artisan('admin:create', ['--role' => 'moderator'])->expectsQuestion('Name', 'Moderator')->expectsQuestion('Email', 'moderator@example.test')->expectsQuestion('Password', 'Very-strong-12345')->expectsQuestion('Confirm password', 'Very-strong-12345')->expectsOutput('Administrator created.')->assertExitCode(0);
        $a = AdminUser::where('email', 'moderator@example.test')->firstOrFail();
        $this->assertTrue(Hash::check('Very-strong-12345', $a->password));
        $this->assertSame('moderator', $a->role);
        $this->get('/admin/register')->assertNotFound();
    }

    public function test_admin_create_rejects_weak_password(): void
    {
        $this->artisan('admin:create')->expectsQuestion('Name', 'Bad')->expectsQuestion('Email', 'bad@example.test')->expectsQuestion('Password', 'tiny')->expectsQuestion('Confirm password', 'tiny')->assertExitCode(1);
        $this->assertDatabaseMissing('admin_users', ['email' => 'bad@example.test']);
    }

    public function test_panel_keeps_csrf_and_separate_guard(): void
    {
        $panel = Filament::getPanel('admin');
        $this->assertSame('admin', $panel->getAuthGuard());
        $this->assertContains(VerifyCsrfToken::class, $panel->getMiddleware());
        $this->assertFalse($panel->hasRegistration());
    }

    public static function statuses(): array
    {
        return [['suspended', 'user.suspend'], ['banned', 'user.ban'], ['active', 'user.restore']];
    }

    #[DataProvider('statuses')]
    public function test_moderation_status_is_audited_and_notification_hides_reason(string $status, string $action): void
    {
        $u = $this->user();
        if ($status === 'active') {
            $u->update(['status' => 'suspended']);
        }app(ModerationService::class)->status($this->admin, $u, UserStatus::from($status), 'Private staff reason');
        $this->assertSame($status, $u->fresh()->status->value);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => $action, 'subject_id' => $u->id, 'reason' => 'Private staff reason']);
        $row = DB::table('social_outbox')->where('source_type', 'account_moderation')->first();
        $this->assertNotNull($row);
        $this->assertStringNotContainsString('Private staff reason', Crypt::decryptString($row->payload));
        app(ModerationService::class)->status($this->admin, $u, UserStatus::from($status), 'retry');
        $this->assertSame(1, AdminAuditLog::count());
    }

    public function test_reason_required_and_finance_cannot_ban(): void
    {
        $u = $this->user();
        $s = app(ModerationService::class);
        $this->rejects(fn () => $s->status($this->admin, $u, UserStatus::Banned, ' '));
        $this->rejects(fn () => $s->status($this->admin('finance_admin'), $u, UserStatus::Banned, 'reason'), AuthorizationException::class);
        $this->assertSame(UserStatus::Active, $u->fresh()->status);
        $this->assertSame(0, AdminAuditLog::count());
    }

    public function test_moderator_cannot_change_finance_and_finance_cannot_operate_games(): void
    {
        $this->rejects(fn () => app(FinanceService::class)->price($this->admin('moderator'), PaidFeature::DirectMessage, 1, true, 'reason'), AuthorizationException::class);
        $this->rejects(fn () => app(GameOperationsService::class)->toggle($this->admin('finance_admin'), 'speed_quiz', false, 'reason'), AuthorizationException::class);
    }

    public function test_role_scoped_panel_routes(): void
    {
        $this->actingAs($this->admin('moderator'), 'admin')->get('/admin/wallets')->assertForbidden();
        $this->get('/admin/users')->assertOk();
        $this->flushSession();
        $this->actingAs($this->admin('finance_admin'), 'admin')->get('/admin/users')->assertForbidden();
        $this->get('/admin/wallets')->assertOk();
    }

    public static function reportStates(): array
    {
        return [['reviewing'], ['resolved'], ['dismissed']];
    }

    #[DataProvider('reportStates')]
    public function test_report_workflow_and_internal_note_privacy(string $status): void
    {
        $r = app(ReportService::class)->create($this->user(), $this->user(), ReportReason::Spam, 'Public complaint');
        $this->assertSame(ReportStatus::Open, $r->status);
        app(ModerationService::class)->report($this->admin, $r->id, ReportStatus::from($status), 'Reviewed', 'Private note', $this->admin->id);
        $this->assertSame($status, $r->fresh()->status->value);
        $this->assertStringNotContainsString('Private note', $r->fresh()->toJson());
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'report.'.$status]);
        $this->actingAs($this->admin, 'admin')->get('/admin/reports')->assertOk()->assertSee('Private note');
    }

    public static function restrictions(): array
    {
        return array_map(fn ($s) => [$s], RestrictionService::TYPES);
    }

    #[DataProvider('restrictions')]
    public function test_restriction_blocks_real_service_then_expires(string $type): void
    {
        $u = $this->user();
        $v = $this->user();
        app(GoldMembershipService::class)->activate($u, now()->addDays(30));
        app(WalletService::class)->credit($u, 100);
        app(ModerationService::class)->restrict($this->admin, $u, $type, 'Abuse review', now()->addMinute());
        $run = match ($type) {
            'bulk_messaging_disabled' => fn () => app(BulkChatRequestService::class)->send($u, [$v], 'restricted-bulk'),'direct_messaging_disabled' => fn () => app(DirectMessageService::class)->send($u, $v, 'Hello', 'restricted-direct'),'chat_requests_disabled' => fn () => app(ChatRequestService::class)->create($u, $v),'game_invites_disabled' => fn () => app(GameService::class)->invite($u, $v, GameType::RockPaperScissors),'event_creation_disabled' => fn () => $this->event($u)
        };
        $this->rejects($run);
        $this->travel(2)->minutes();
        $run();
        $this->assertSame(1, AdminAuditLog::where('action', 'restriction.create')->count());
    }

    public function test_direct_restriction_also_blocks_gold_bulk_direct(): void
    {
        $u = $this->user();
        app(GoldMembershipService::class)->activate($u, now()->addDay());
        app(ModerationService::class)->restrict($this->admin, $u, 'direct_messaging_disabled', 'Spam');
        $this->rejects(fn () => app(BulkDirectMessageService::class)->send($u, [$this->user()], 'hello', 'bulk-key'));
        $this->assertDatabaseCount('direct_messages', 0);
    }

    public function test_lifting_restriction_is_audited(): void
    {
        $u = $this->user();
        $id = app(ModerationService::class)->restrict($this->admin, $u, 'game_invites_disabled', 'Review');
        app(ModerationService::class)->lift($this->admin, $id, 'Cleared');
        app(RestrictionService::class)->authorize($u, 'game_invites_disabled');
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'restriction.lift']);
    }

    public function test_suspended_stale_user_is_denied_across_services(): void
    {
        $u = $this->user();
        $v = $this->user();
        app(ModerationService::class)->status($this->admin, $u, UserStatus::Suspended, 'review');
        foreach ([fn () => app(ChatRequestService::class)->create($u, $v), fn () => app(DirectMessageService::class)->send($u, $v, 'hello'), fn () => $this->event($u), fn () => app(GameService::class)->invite($u, $v, GameType::RockPaperScissors), fn () => app(DiscoveryService::class)->city($u, Gender::Male)] as $fn) {
            $this->rejects($fn);
        }$this->assertDatabaseCount('coin_transactions', 0);
    }

    public function test_existing_chat_and_acceptance_become_unavailable(): void
    {
        $u = $this->user();
        $v = $this->user();
        app(WalletService::class)->credit($u, 20);
        $r = app(ChatRequestService::class)->create($u, $v);
        $c = app(ChatRequestService::class)->accept($v, $r);
        app(ModerationService::class)->status($this->admin, $u, UserStatus::Banned, 'Abuse');
        $this->assertFalse(app(ConversationService::class)->canAccess($v, $c));
        $this->rejects(fn () => app(ConversationService::class)->send($v, $c, MessageType::Text, 'hello'));
        $this->rejects(fn () => app(ChatRequestService::class)->accept($v, $r));
    }

    public function test_wallet_actions_are_idempotent_audited_and_preserve_ledger(): void
    {
        $u = $this->user();
        $s = app(FinanceService::class);
        $credit = $s->wallet($this->admin, $u, 20, true, 'Support credit', 'credit-key-001');
        $this->assertSame($credit->id, $s->wallet($this->admin, $u, 20, true, 'Retry', 'credit-key-001')->id);
        $debit = $s->wallet($this->admin, $u, 5, false, 'Correction', 'debit-key-001');
        $before = $debit->fresh()->getRawOriginal();
        $refund = $s->refund($this->admin, $debit, 'Refund requested');
        $this->assertSame($refund->id, $s->refund($this->admin, $debit, 'Retry')->id);
        $this->assertSame(20, app(WalletService::class)->wallet($u)->balance);
        $this->assertSame($before, $debit->fresh()->getRawOriginal());
        $this->assertSame(3, CoinTransaction::count());
        foreach (['wallet.credit', 'wallet.debit', 'wallet.refund'] as $action) {
            $this->assertDatabaseHas('admin_audit_logs', ['action' => $action]);
        }$this->assertSame(3, AdminAuditLog::count());
    }

    public function test_wallet_negative_balance_and_invalid_refund_rejected(): void
    {
        $u = $this->user();
        $s = app(FinanceService::class);
        $this->rejects(fn () => $s->wallet($this->admin, $u, 1, false, 'No funds', 'debit-key'), InsufficientCoinsException::class);
        $this->rejects(fn () => $s->wallet($this->admin, $u, -1, true, 'Bad', 'negative-key'));
        $tx = $s->wallet($this->admin, $u, 1, true, 'Credit', 'credit-key');
        $this->rejects(fn () => $s->refund($this->admin, $tx, 'Invalid refund'));
        $this->assertSame(1, CoinTransaction::count());
    }

    public static function immutableTables(): array
    {
        return [['coin_transactions', 'update'], ['coin_transactions', 'delete'], ['admin_audit_logs', 'update'], ['admin_audit_logs', 'delete']];
    }

    #[DataProvider('immutableTables')]
    public function test_database_history_is_immutable(string $table, string $operation): void
    {
        $u = $this->user();
        app(FinanceService::class)->wallet($this->admin, $u, 10, true, 'Credit', 'immutable-key');
        $this->rejects(fn () => DB::transaction(fn () => $operation === 'delete' ? DB::table($table)->delete() : DB::table($table)->update([$table === 'coin_transactions' ? 'amount' : 'reason' => $table === 'coin_transactions' ? 100 : 'tampered'])), QueryException::class);
        $this->assertSame(1, DB::table($table)->count());
    }

    public function test_future_pricing_changes_leave_old_ledger_unchanged(): void
    {
        $u = $this->user();
        $v = $this->user();
        app(WalletService::class)->credit($u, 100);
        $s = app(FinanceService::class);
        $s->price($this->admin, PaidFeature::DirectMessage, 3, true, 'New price');
        app(DirectMessageService::class)->send($u, $v, 'First', 'price-first');
        $old = CoinTransaction::where('code', 'direct_message')->first();
        $s->price($this->admin, PaidFeature::DirectMessage, 7, true, 'New price');
        app(DirectMessageService::class)->send($u, $v, 'Second', 'price-second');
        $this->assertDatabaseHas('coin_transactions', ['amount' => -3]);
        $this->assertDatabaseHas('coin_transactions', ['amount' => -7]);
        $this->assertSame(90, app(WalletService::class)->wallet($u)->balance);
        $this->assertSame(2, AdminAuditLog::where('action', 'pricing.change')->count());
    }

    public function test_package_create_update_deactivate_and_validation(): void
    {
        $s = app(FinanceService::class);
        $data = ['name' => 'Pack', 'base_coins' => 100, 'bonus_coins' => 5, 'price_amount' => 500, 'currency' => 'IRR', 'is_active' => true, 'is_featured' => true, 'sort_order' => 2, 'starts_at' => now()->subMinute(), 'ends_at' => now()->addDay()];
        $p = $s->package($this->admin, null, $data, 'Create');
        $this->assertTrue($p->available());
        $p = $s->package($this->admin, $p, array_replace($data, ['is_active' => false]), 'Deactivate');
        $this->assertFalse($p->available());
        $this->assertSame(2, AdminAuditLog::where('action', 'package.save')->count());
        $this->rejects(fn () => $s->package($this->admin, null, array_replace($data, ['base_coins' => -1]), 'Invalid'), ValidationException::class);
    }

    public function test_gold_grant_revoke_duration_and_settings(): void
    {
        $u = $this->user();
        $s = app(FinanceService::class);
        $s->setting($this->admin, 'gold_duration_days', 12, 'Plan');
        $m = $s->grantGold($this->admin, $u, null, 'Gift');
        $this->assertTrue(app(GoldMembershipService::class)->isGold($u));
        $this->assertEqualsWithDelta(12, now()->diffInDays($m->ends_at), 0.01);
        $s->revokeGold($this->admin, $m, 'Revoked');
        $this->assertFalse(app(GoldMembershipService::class)->isGold($u));
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'gold.grant']);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'gold.revoke']);
        $s->setting($this->admin, 'gold_bulk_max_recipients_per_action', 3, 'Limit');
        $this->assertSame(3, app(GoldLimitsService::class)->maxRecipients());
    }

    public function test_economy_setting_allowlist_blocks_raw_score_or_balance(): void
    {
        foreach (['balance', 'xp', 'competitive_score', 'password'] as $key) {
            $this->rejects(fn () => app(FinanceService::class)->setting($this->admin, $key, 99, 'attempt'));
        }$this->assertSame(0, AdminAuditLog::count());
    }

    public function test_event_cancellation_preserves_history_and_queues_once(): void
    {
        $u = $this->user();
        $v = $this->user();
        $e = $this->event($u);
        app(EventService::class)->join($v, $e);
        $s = app(ModerationService::class);
        $s->cancelEvent($this->admin, $e, 'Safety');
        $s->cancelEvent($this->admin, $e, 'Retry');
        $this->assertDatabaseHas('events', ['id' => $e->id, 'status' => 'cancelled']);
        $this->assertDatabaseHas('event_participants', ['event_id' => $e->id, 'user_id' => $v->id]);
        $this->assertSame(1, DB::table('social_outbox')->where('source_type', 'event_cancelled')->count());
        $this->assertSame(1, AdminAuditLog::where('action', 'event.cancel')->count());
        $this->rejects(fn () => $s->cancelEvent($this->admin('finance_admin'), $e, 'No role'), AuthorizationException::class);
    }

    public static function gameTypes(): array
    {
        return array_map(fn ($t) => [$t->value], GameType::cases());
    }

    #[DataProvider('gameTypes')]
    public function test_game_disable_blocks_new_invitation(string $type): void
    {
        app(GameOperationsService::class)->toggle($this->admin, $type, false, 'Maintenance');
        $this->rejects(fn () => app(GameService::class)->invite($this->user(), $this->user(), GameType::from($type)));
        $this->assertDatabaseCount('game_sessions', 0);
    }

    public function test_active_game_can_finish_but_waiting_game_cannot_start_after_disable(): void
    {
        $u = $this->user();
        $v = $this->user();
        $g = app(GameService::class);
        $s = $g->invite($u, $v, GameType::RockPaperScissors);
        $g->accept($v, $s);
        app(GameOperationsService::class)->toggle($this->admin, 'rock_paper_scissors', false, 'Maintenance');
        $g->assertPlayable($u, $s);
        $this->assertSame('active', $s->fresh()->status->value);
        $g->answer($u, $s, 'rock');
        $g->answer($v, $s, 'scissors');
        $g->answer($u, $s->fresh(), 'rock');
        $g->answer($v, $s->fresh(), 'scissors');
        $this->assertSame('completed', $s->fresh()->status->value);
    }

    public function test_stuck_game_cancel_clears_state_without_rewards(): void
    {
        $u = $this->user();
        $v = $this->user();
        $s = app(GameService::class)->invite($u, $v, GameType::RockPaperScissors);
        InteractionState::create(['user_id' => $u->id, 'mode' => 'game_number', 'game_context' => ['session_id' => $s->id]]);
        app(GameOperationsService::class)->cancel($this->admin, $s, 'Stuck');
        app(GameOperationsService::class)->cancel($this->admin, $s, 'Retry');
        $this->assertSame('cancelled', $s->fresh()->status->value);
        $this->assertDatabaseHas('interaction_states', ['user_id' => $u->id, 'mode' => 'menu', 'game_context' => null]);
        $this->assertDatabaseCount('game_rating_events', 0);
        $this->assertDatabaseCount('game_player_stats', 0);
        $this->assertDatabaseCount('coin_transactions', 0);
        $this->assertSame(1, AdminAuditLog::where('action', 'game.cancel')->count());
    }

    public function test_daily_challenge_toggle_is_enforced(): void
    {
        $u = $this->user();
        $q = app(DailyChallengeService::class)->question($u);
        app(GameOperationsService::class)->toggle($this->admin, 'daily_challenge', false, 'Maintenance');
        $this->rejects(fn () => app(DailyChallengeService::class)->question($u));
        $this->rejects(fn () => app(DailyChallengeService::class)->answer($u, $q['date'], 0));
        $this->assertDatabaseCount('daily_challenge_completions', 0);
    }

    public function test_valid_quiz_and_social_content_saved_audited(): void
    {
        $s = app(GameOperationsService::class);
        $data = ['question' => 'Pick one', 'options' => ['A', 'B'], 'correct_option' => 'B', 'is_active' => true];
        $id = $s->question($this->admin, null, $data, false, 'New quiz');
        $this->assertDatabaseHas('quiz_questions', ['id' => $id, 'correct_option' => 'B', 'category' => 'general']);
        $other = $s->question($this->admin, null, $data, true, 'New social');
        $this->assertDatabaseHas('quiz_questions', ['id' => $other, 'category' => 'this_or_that', 'correct_option' => '']);
        $s->question($this->admin, $id, array_replace($data, ['is_active' => false]), false, 'Retire');
        $this->assertDatabaseHas('quiz_questions', ['id' => $id, 'is_active' => false]);
        $this->assertSame(3, AdminAuditLog::where('action', 'question.save')->count());
    }

    public static function malformed(): array
    {
        return [[['A', 'A'], 'A', false], [['A', 'a'], 'A', false], [['A', 'B'], 'C', false], [['A', ' '], 'A', false], [['A', 'B', 'C'], 'A', true]];
    }

    #[DataProvider('malformed')]
    public function test_malformed_content_cannot_enter_pool(array $options, string $answer, bool $social): void
    {
        $count = DB::table('quiz_questions')->count();
        $this->rejects(fn () => app(GameOperationsService::class)->question($this->admin, null, ['question' => 'Q', 'options' => $options, 'correct_option' => $answer, 'is_active' => true], $social, 'Bad content'), in_array(' ', $options, true) ? ValidationException::class : \DomainException::class);
        $this->assertSame($count, DB::table('quiz_questions')->count());
    }

    public function test_dashboard_snapshot_is_bounded_and_contains_operational_metrics(): void
    {
        $u = $this->user();
        app(WalletService::class)->credit($u, 17);
        $m = app(OperationsMetrics::class)->snapshot($this->admin);
        $this->assertCount(11, $m);
        $this->assertSame(1, $m['Total users']);
        $this->assertSame(1, $m['Completed profiles']);
        $this->assertEquals(17, $m['Coins in wallets']);
    }

    public function test_panel_never_displays_telegram_identifiers_or_game_secrets(): void
    {
        $u = $this->user();
        $u->update(['telegram_username' => 'private_identity']);
        $v = $this->user();
        $s = app(GameService::class)->invite($u, $v, GameType::GuessNumber);
        app(GameService::class)->accept($v, $s);
        $this->actingAs($this->admin, 'admin')->get('/admin/users')->assertOk()->assertDontSee('private_identity')->assertDontSee((string) $u->telegram_user_id);
        $this->get('/admin/game-sessions')->assertOk()->assertDontSee('secret_number')->assertDontSee('correct_option');
    }

    public function test_raw_mutation_permissions_are_denied(): void
    {
        $this->actingAs($this->admin, 'admin');
        foreach ([WalletResource::class, TransactionResource::class, GameSessionResource::class, BlockResource::class] as $r) {
            $this->assertFalse($r::canCreate());
            $this->assertFalse($r::canDeleteAny());
            $this->assertFalse($r::can('update'));
        }
    }

    public function test_user_suspend_action_executes_through_filament(): void
    {
        $u = $this->user();
        $this->actingAs($this->admin, 'admin');
        Livewire::test(ManageUser::class)->callTableAction('Suspend', $u, data: ['reason' => 'Panel moderation'])->assertHasNoActionErrors();
        $this->assertSame(UserStatus::Suspended, $u->fresh()->status);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'user.suspend']);
    }

    public static function interactionModes(): array
    {
        return [['chat'], ['event_create'], ['game_guess_number'], ['bulk_direct']];
    }

    #[DataProvider('interactionModes')]
    public function test_moderation_is_immediate_in_telegram_handlers(string $mode): void
    {
        $u = $this->user();
        InteractionState::create(['user_id' => $u->id, 'mode' => $mode, 'game_context' => ['session_id' => 999], 'event_context' => ['title' => 'Draft'], 'bulk_selection' => [999]]);
        app(ModerationService::class)->status($this->admin, $u, UserStatus::Banned, 'Private reason');
        $id = random_int(10000, 99999);
        $payload = ['update_id' => $id, 'message' => ['message_id' => 1, 'chat' => ['id' => $u->telegram_user_id, 'type' => 'private'], 'from' => ['id' => $u->telegram_user_id, 'is_bot' => false, 'first_name' => 'Player'], 'text' => 'continue']];
        app(AcceptUpdate::class)->execute($payload);
        app()->call([new ProcessUpdate($id), 'handle']);
        $row = DB::table('telegram_outbox')->where('update_id', $id)->first();
        $text = Crypt::decryptString($row->payload);
        $this->assertStringContainsString('Your account is unavailable.', $text);
        $this->assertStringNotContainsString('Private reason', $text);
        $this->assertDatabaseHas('interaction_states', ['user_id' => $u->id, 'mode' => 'menu', 'game_context' => null, 'event_context' => null, 'bulk_selection' => null]);
        $this->assertDatabaseCount('coin_transactions', 0);
    }

    public function test_disabled_feature_is_not_free_and_reenable_uses_current_price(): void
    {
        $u = $this->user();
        $v = $this->user();
        app(WalletService::class)->credit($u, 20);
        app(FinanceService::class)->price($this->admin, PaidFeature::DirectMessage, 4, false, 'Maintenance');
        $this->rejects(fn () => app(DirectMessageService::class)->send($u, $v, 'hello'));
        $this->assertSame(20, app(WalletService::class)->wallet($u)->balance);
        app(FinanceService::class)->price($this->admin, PaidFeature::DirectMessage, 4, true, 'Restored');
        app(DirectMessageService::class)->send($u, $v, 'hello');
        $this->assertSame(16, app(WalletService::class)->wallet($u)->balance);
    }

    public function test_waiting_invitation_cannot_accept_disabled_type(): void
    {
        $u = $this->user();
        $v = $this->user();
        $s = app(GameService::class)->invite($u, $v, GameType::RockPaperScissors);
        app(GameOperationsService::class)->toggle($this->admin, 'rock_paper_scissors', false, 'Maintenance');
        $this->rejects(fn () => app(GameService::class)->accept($v, $s));
        $this->assertSame('waiting', $s->fresh()->status->value);
        $this->assertDatabaseCount('game_rating_events', 0);
    }

    public function test_gold_priority_setting_applies_to_discovery_only(): void
    {
        $this->travelTo(CarbonImmutable::parse('2030-01-01 12:00:00'));
        $u = $this->user();
        $normal = $this->user();
        $gold = $this->user();
        $normal->update(['last_activity_at' => now()]);
        $gold->update(['last_activity_at' => now()->subHour()]);
        app(GoldMembershipService::class)->activate($gold, now()->addDay());
        $service = app(DiscoveryService::class);
        $this->assertSame($gold->id, $service->city($u, Gender::Male)->first()->user_id);
        app(FinanceService::class)->setting($this->admin, 'gold_priority_enabled', 0, 'Disable priority');
        $this->assertSame($normal->id, $service->city($u, Gender::Male)->first()->user_id);
    }

    public function test_event_report_workflow_is_audited(): void
    {
        $u = $this->user();
        $v = $this->user();
        $e = $this->event($u);
        app(EventService::class)->report($v, $e, EventReportReason::Other, 'Review');
        $id = DB::table('event_reports')->value('id');
        app(ModerationService::class)->report($this->admin, $id, ReportStatus::Resolved, 'Reviewed', 'Internal event note', $this->admin->id, true);
        $this->assertDatabaseHas('event_reports', ['id' => $id, 'status' => 'resolved']);
        $this->assertDatabaseHas('admin_audit_logs', ['subject_type' => 'event_reports', 'subject_id' => $id]);
    }

    public function test_wallet_actions_work_through_filament_without_raw_edit(): void
    {
        $u = $this->user();
        $w = app(WalletService::class)->wallet($u);
        $this->actingAs($this->admin, 'admin');
        Livewire::test(ManageWallet::class)->callTableAction('Credit coins', $w, data: ['amount' => 20, 'operation_key' => 'panel-credit-key', 'reason' => 'Credit'])->assertHasNoActionErrors();
        Livewire::test(ManageWallet::class)->callTableAction('Debit coins', $w, data: ['amount' => 5, 'operation_key' => 'panel-debit-key', 'reason' => 'Debit'])->assertHasNoActionErrors();
        $this->assertSame(15, $w->fresh()->balance);
        $tx = CoinTransaction::where('amount', -5)->firstOrFail();
        Livewire::test(ManageTransaction::class)->callTableAction('Refund', $tx, data: ['reason' => 'Refund'])->assertHasNoActionErrors();
        $this->assertSame(20, $w->fresh()->balance);
    }

    public function test_report_review_and_suspend_work_through_filament(): void
    {
        $u = $this->user();
        $r = app(ReportService::class)->create($this->user(), $u, ReportReason::Spam);
        $this->actingAs($this->admin, 'admin');
        Livewire::test(ManageReport::class)->callTableAction('Review', $r, data: ['status' => 'reviewing', 'reason' => 'Investigating', 'internal_notes' => 'Staff only', 'assigned_admin_id' => $this->admin->id])->assertHasNoActionErrors();
        $this->assertSame('reviewing', $r->fresh()->status->value);
        Livewire::test(ManageReport::class)->callTableAction('Suspend reported user', $r, data: ['reason' => 'Confirmed'])->assertHasNoActionErrors();
        $this->assertSame(UserStatus::Suspended, $u->fresh()->status);
    }

    public function test_settings_and_game_toggle_actions_work_through_filament(): void
    {
        $this->actingAs($this->admin, 'admin');
        Livewire::test(EconomySettings::class)->callAction('Change setting', data: ['key' => 'signup_bonus', 'value' => 19, 'reason' => 'New bonus'])->assertHasNoActionErrors();
        $this->assertDatabaseHas('economy_settings', ['key' => 'signup_bonus', 'value' => '19']);
        Livewire::test(GameControls::class)->callAction('Set availability', data: ['type' => 'guess_number', 'enabled' => false, 'reason' => 'Maintenance'])->assertHasNoActionErrors();
        $this->assertDatabaseHas('economy_settings', ['key' => 'game_enabled_guess_number', 'value' => '0']);
    }

    public function test_content_action_creates_and_validates_quiz(): void
    {
        $this->actingAs($this->admin, 'admin');
        Livewire::test(ManageQuiz::class)->callTableAction('Save question', data: ['question' => 'Panel question', 'options' => ['Yes', 'No'], 'correct_option' => 'Yes', 'is_active' => true, 'reason' => 'Reviewed'])->assertHasNoActionErrors();
        $this->assertDatabaseHas('quiz_questions', ['question' => 'Panel question', 'correct_option' => 'Yes']);
    }

    public function test_game_cancel_and_event_cancel_actions_work_through_filament(): void
    {
        $u = $this->user();
        $v = $this->user();
        $e = $this->event($u);
        $g = app(GameService::class)->invite($u, $v, GameType::RockPaperScissors);
        $this->actingAs($this->admin, 'admin');
        Livewire::test(ManageEvent::class)->callTableAction('Cancel event', $e, data: ['reason' => 'Safety'])->assertHasNoActionErrors();
        Livewire::test(ManageGameSession::class)->callTableAction('Cancel session', $g, data: ['reason' => 'Stuck'])->assertHasNoActionErrors();
        $this->assertSame('cancelled', $e->fresh()->status->value);
        $this->assertSame('cancelled', $g->fresh()->status->value);
    }

    public function test_revoked_admin_cannot_execute_stale_action(): void
    {
        $u = $this->user();
        $this->actingAs($this->admin, 'admin');
        $component = Livewire::test(ManageUser::class);
        AdminUser::whereKey($this->admin->id)->update(['is_active' => false]);
        $this->rejects(fn () => $component->callTableAction('Ban', $u, data: ['reason' => 'Stale session']), AuthorizationException::class);
        $this->assertSame(UserStatus::Active, $u->fresh()->status);
    }

    public function test_report_content_cannot_read_unrelated_private_messages(): void
    {
        $a = $this->user();
        $b = $this->user();
        $attacker = $this->user();
        $request = app(ChatRequestService::class)->create($a, $b);
        $c = app(ChatRequestService::class)->accept($b, $request);
        $m = app(ConversationService::class)->send($a, $c, MessageType::Text, 'Private conversation');
        $r = app(ReportService::class)->create($attacker, $a, ReportReason::Spam, null, $m->id);
        $this->assertSame('Referenced content unavailable.', app(ReportInspection::class)->content($this->admin, $r));
    }

    public function test_seeders_preserve_admin_configuration_and_disabled_content(): void
    {
        app(FinanceService::class)->price($this->admin, PaidFeature::DirectMessage, 9, false, 'Product decision');
        app(FinanceService::class)->setting($this->admin, 'signup_bonus', 17, 'Product decision');
        app(FinanceService::class)->setting($this->admin, 'gold_duration_days', 22, 'Product decision');
        $p = CoinPackage::first();
        $data = $p->toArray();
        $data['is_active'] = false;
        $data['base_coins'] = 123;
        app(FinanceService::class)->package($this->admin, $p, $data, 'Retire');
        $q = DB::table('quiz_questions')->where('category', 'general')->first();
        app(GameOperationsService::class)->question($this->admin, $q->id, ['question' => $q->question, 'options' => ['X', 'Y'], 'correct_option' => 'Y', 'is_active' => false], false, 'Retire');
        $counts = [CoinPackage::count(), DB::table('quiz_questions')->count()];
        $this->seed();
        $this->seed();
        $this->assertSame($counts, [CoinPackage::count(), DB::table('quiz_questions')->count()]);
        $this->assertDatabaseHas('coin_feature_prices', ['feature_code' => 'direct_message', 'coin_cost' => 9, 'is_active' => false]);
        $this->assertDatabaseHas('economy_settings', ['key' => 'signup_bonus', 'value' => '17']);
        $this->assertDatabaseHas('economy_settings', ['key' => 'gold_duration_days', 'value' => '22']);
        $this->assertDatabaseHas('coin_packages', ['id' => $p->id, 'is_active' => false, 'base_coins' => 123]);
        $this->assertDatabaseHas('quiz_questions', ['id' => $q->id, 'is_active' => false, 'correct_option' => 'Y']);
    }

    public function test_actual_csrf_middleware_rejects_admin_post_without_token(): void
    {
        $this->actingAs($this->admin, 'admin');
        $this->app->instance('env', 'production');
        $this->post('/admin/logout')->assertStatus(419);
    }

    public function test_restricted_telegram_direct_action_is_acknowledged_safely(): void
    {
        $u = $this->user();
        $v = $this->user();
        RegistrationState::create(['user_id' => $u->id, 'step' => 'complete']);
        InteractionState::create(['user_id' => $u->id, 'mode' => 'direct', 'direct_recipient_id' => $v->id]);
        app(ModerationService::class)->restrict($this->admin, $u, 'direct_messaging_disabled', 'Staff only');
        $id = random_int(10000, 99999);
        app(AcceptUpdate::class)->execute(['update_id' => $id, 'message' => ['message_id' => 1, 'chat' => ['id' => $u->telegram_user_id, 'type' => 'private'], 'from' => ['id' => $u->telegram_user_id, 'is_bot' => false, 'first_name' => 'Player'], 'text' => 'hello']]);
        app()->call([new ProcessUpdate($id), 'handle']);
        $this->assertNotNull(DB::table('telegram_updates')->where('update_id', $id)->value('processed_at'));
        $this->assertDatabaseCount('direct_messages', 0);
        $preview = json_decode(Crypt::decryptString(DB::table('telegram_outbox')->where('update_id', $id)->value('payload')), true, 512, JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('hello', $preview['parameters']['text']);
        $callback = $preview['parameters']['reply_markup']['inline_keyboard'][0][0]['callback_data'];

        $confirmId = $id + 1;
        app(AcceptUpdate::class)->execute(['update_id' => $confirmId, 'callback_query' => ['id' => 'direct-confirm', 'data' => $callback, 'from' => ['id' => $u->telegram_user_id, 'is_bot' => false, 'first_name' => 'Player'], 'message' => ['message_id' => 2, 'chat' => ['id' => $u->telegram_user_id, 'type' => 'private']]]]);
        app()->call([new ProcessUpdate($confirmId), 'handle']);

        $this->assertDatabaseCount('direct_messages', 0);
        $text = DB::table('telegram_outbox')->where('update_id', $confirmId)->pluck('payload')->map(fn (string $payload): string => Crypt::decryptString($payload))->implode("\n");
        $this->assertStringContainsString('restricted', $text);
        $this->assertStringNotContainsString('Staff only', $text);
    }

    public function test_package_window_can_have_only_an_end_and_rejects_reversed_dates(): void
    {
        $data = ['name' => 'Window', 'base_coins' => 10, 'bonus_coins' => 0, 'price_amount' => 100, 'currency' => 'IRR', 'is_active' => true, 'is_featured' => false, 'sort_order' => 0, 'starts_at' => null, 'ends_at' => now()->addDay()];
        $p = app(FinanceService::class)->package($this->admin, null, $data, 'Window');
        $this->assertTrue($p->available());
        $data['starts_at'] = now()->addDays(2);
        $this->rejects(fn () => app(FinanceService::class)->package($this->admin, $p, $data, 'Invalid'), ValidationException::class);
    }

    public function test_package_and_gold_header_actions_work_through_panel(): void
    {
        $this->actingAs($this->admin, 'admin');
        Livewire::test(ManageCoinPackage::class)->callTableAction('Save package', data: ['name' => 'Panel package', 'base_coins' => 20, 'bonus_coins' => 2, 'price_amount' => 100, 'currency' => 'IRR', 'is_active' => true, 'is_featured' => true, 'sort_order' => 1, 'starts_at' => null, 'ends_at' => null, 'reason' => 'Launch'])->assertHasNoActionErrors();
        $this->assertDatabaseHas('coin_packages', ['name' => 'Panel package', 'base_coins' => 20]);
        $u = $this->user();
        Livewire::test(ManageGoldMembership::class)->callTableAction('Grant Gold', data: ['user_id' => $u->id, 'days' => 3, 'reason' => 'Gift'])->assertHasNoActionErrors();
        $m = app(GoldMembershipService::class)->active($u);
        $this->assertNotNull($m);
        Livewire::test(ManageGoldMembership::class)->callTableAction('Revoke', $m, data: ['reason' => 'Cancel'])->assertHasNoActionErrors();
        $this->assertFalse(app(GoldMembershipService::class)->isGold($u));
    }

    public function test_admin_zero_price_identity_share_completes_once_and_respects_suspension(): void
    {
        $u = $this->user();
        $v = $this->user();
        $u->update(['telegram_username' => 'consenting_one']);
        $v->update(['telegram_username' => 'consenting_two']);
        app(FinanceService::class)->price($this->admin, PaidFeature::TelegramIdShare, 0, true, 'Free campaign');
        $svc = app(IdentityShareService::class);
        $r = $svc->request($u, $v);
        app(ModerationService::class)->status($this->admin, $u, UserStatus::Suspended, 'Review');
        $this->rejects(fn () => $svc->accept($v, $r));
        app(ModerationService::class)->status($this->admin, $u, UserStatus::Active, 'Restored');
        $svc->accept($v, $r);
        $svc->accept($v, $r);
        $this->assertDatabaseHas('telegram_identity_share_requests', ['id' => $r->id, 'status' => 'accepted']);
        $this->assertDatabaseCount('telegram_identity_share_events', 1);
        $this->assertDatabaseCount('coin_transactions', 1);
        $this->assertDatabaseHas('coin_transactions', ['amount' => 0, 'code' => 'telegram_id_share']);
        $this->assertSame(0, app(WalletService::class)->wallet($u)->balance);
    }
}
