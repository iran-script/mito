<?php

namespace Tests\Feature;

use App\Domain\Admin\AdminUser;
use App\Domain\Chat\ChatRequest;
use App\Domain\Events\EventStatus;
use App\Domain\Games\GameService;
use App\Domain\Games\GameType;
use App\Domain\Moderation\ReportStatus;
use App\Domain\Payments\PaidFeature;
use App\Domain\Profiles\ProfileStatus;
use App\Domain\Profiles\PublicProfile;
use App\Domain\Profiles\RegistrationState;
use App\Domain\Telegram\IncomingUpdate;
use App\Domain\Telegram\InteractionState;
use App\Domain\Telegram\Keyboard;
use App\Domain\Telegram\RegistrationPresenter;
use App\Domain\Telegram\SocialInteraction;
use App\Domain\Users\UserStatus;
use App\Filament\Support\AdminActions;
use App\Support\Presentation;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithGuessGames;
use Tests\TestCase;

class PersianLocalizationTest extends TestCase
{
    use InteractsWithGuessGames, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootGameTests();
        DB::table('coin_feature_prices')->where('feature_code', 'game_invitation')->update(['coin_cost' => 0]);
        app()->setLocale('fa');
    }

    private function type(): GameType
    {
        return GameType::GuessNumber;
    }

    private function incoming($user, string $callback): IncomingUpdate
    {
        return new IncomingUpdate(['update_id' => 1, 'callback_query' => ['id' => 'fa', 'data' => $callback, 'from' => ['id' => $user->telegram_user_id, 'is_bot' => false, 'first_name' => 'Test'], 'message' => ['chat' => ['id' => $user->telegram_user_id, 'type' => 'private']]]]);
    }

    private function assertPersian(string $text): void
    {
        $this->assertMatchesRegularExpression('/[\x{0600}-\x{06ff}]/u', $text);
        $this->assertDoesNotMatchRegularExpression('/[A-Za-z]/u', $text);
    }

    public function test_start_delivers_persian_through_webhook_and_outbox(): void
    {
        $u = $this->user('سارا');
        $u->profile->update(['status' => ProfileStatus::Draft]);
        RegistrationState::where('user_id', $u->id)->update(['step' => 'name']);
        $this->send($u, '/start', false);
        $this->assertStringContainsString('چه اسمی', $this->delivered($u));
        $this->assertPrivateMessages($u);
    }

    public function test_registration_gender_and_navigation_are_persian_with_original_callbacks(): void
    {
        $u = $this->user('سارا');
        $u->profile->update(['status' => ProfileStatus::Draft]);
        $state = RegistrationState::where('user_id', $u->id)->first();
        $state->update(['step' => 'gender']);
        $message = app(RegistrationPresenter::class)->messages($u->profile->fresh(), $state, null)[0]['parameters'];
        $this->assertPersian($message['text']);
        $buttons = array_merge(...$message['reply_markup']['keyboard']);
        $this->assertSame([__('Man'), __('Woman'), __('Back')], $buttons);
        $this->assertSame('male', Keyboard::registrationChoice(__('Man'), $state));
        $this->assertSame('female', Keyboard::registrationChoice(__('Woman'), $state));
    }

    public static function menus(): array
    {
        return [['menu', '@Welcome to Mito. What would you like to do?'], ['search', '@Who would you like to see?'], ['gold', 'طلایی'], ['events', 'رویدادها'], ['games', '@Choose one of these games:'], ['game_leaderboard', 'رتبه‌بندی'], ['game_stats', 'آمار'], ['game_badges', 'نشان']];
    }

    #[DataProvider('menus')]
    public function test_real_bot_menus_are_persian(string $action, string $expected): void
    {
        $u = $this->user('سارا');
        $this->send($u, 'd:0:'.$action);
        $this->assertStringContainsString(str_starts_with($expected, '@') ? __(substr($expected, 1)) : $expected, $this->delivered($u));
        foreach ($this->telegram->sent as $m) {
            if (isset($m['parameters']['text'])) {
                $this->assertPersian($m['parameters']['text']);
            }
            foreach ($m['parameters']['reply_markup']['inline_keyboard'] ?? [] as $row) {
                foreach ($row as $button) {
                    $this->assertPersian($button['text']);
                    $this->assertMatchesRegularExpression('/^[ds]:[0-9]+:[a-z0-9_]+$/', $button['callback_data']);
                }
            }
        }
        $this->assertPrivateMessages($u);
    }

    public function test_wallet_packages_and_balance_error_are_persian(): void
    {
        $u = $this->user('سارا');
        $state = InteractionState::create(['user_id' => $u->id]);
        foreach (['wallet', 'buy_coins', 'transactions'] as $action) {
            $messages = app(SocialInteraction::class)->handle($u, $this->incoming($u, 's:0:'.$action), $state);
            $this->assertPersian($messages[0]['parameters']['text']);
        }
        $this->assertSame(__('This action requires :required coins. Your balance is :balance.', ['required' => 5, 'balance' => 2]), Presentation::error('This action requires 5 coins. Your balance is 2.'));
    }

    public function test_chat_request_acceptance_notifications_are_persian(): void
    {
        $a = $this->user('Sara');
        $b = $this->user('Ali');
        $this->send($a, 's:0:request_'.$b->id);
        $this->assertStringContainsString(__('Chat request sent. Waiting for a reply.'), $this->delivered($a));
        $this->assertStringContainsString(__('You have a new chat request.'), $this->delivered($b));
        $request = ChatRequest::firstOrFail();
        $this->send($b, 'n:0:view_request_'.$request->id);
        $revision = InteractionState::where('user_id', $b->id)->value('revision');
        $this->send($b, 's:'.$revision.':accept_request_'.$request->id);
        $this->assertStringContainsString(__('Your chat request was accepted. You can now chat with :mito_id.', [
            'mito_id' => '/'.$b->public_mito_id,
        ]), $this->delivered($a));
        $this->assertSame('accepted', $request->fresh()->getRawOriginal('status'));
    }

    public static function labels(): array
    {
        return [['active', 'فعال'], ['suspended', 'تعلیق‌شده'], ['banned', 'مسدودشده'], ['open', 'باز'], ['reviewing', 'در حال بررسی'], ['resolved', 'رسیدگی‌شده'], ['dismissed', 'رد گزارش'], ['accepted', 'پذیرفته‌شده'], ['seen', 'دیده‌شده'], ['published', 'منتشرشده'], ['cancelled', 'لغوشده'], ['Gold II', 'طلایی 2'], ['Platinum', 'پلاتینی'], ['hiking', 'طبیعت‌گردی'], ['direct_message', 'پیام دایرکت']];
    }

    #[DataProvider('labels')]
    public function test_centralized_labels_preserve_codes(string $code, string $label): void
    {
        $this->assertSame($label, Presentation::label($code));
        app()->setLocale('en');
        $this->assertSame($code, Presentation::label($code));
    }

    public function test_all_game_and_status_enums_have_persian_presentation(): void
    {
        foreach ([GameType::class, UserStatus::class, ReportStatus::class, EventStatus::class, PaidFeature::class] as $enum) {
            foreach ($enum::cases() as $case) {
                $this->assertPersian(Presentation::label($case));
                $this->assertMatchesRegularExpression('/^[a-z_]+$/', $case->value);
                $this->assertSame($case, $enum::from($case->value));
            }
        }
        $this->assertSame(['direct_message', 'chat_acceptance', 'telegram_id_share'], array_column(PaidFeature::cases(), 'value'));
    }

    public function test_guess_number_persian_input_and_result_preserve_gameplay(): void
    {
        [$a, $b, $session] = $this->game();
        $this->send($a, 'd:0:game_gn_open_'.$session->id);
        $secret = (string) $session->state['gn_secret'];
        $persian = strtr($secret, array_combine(str_split('0123456789'), preg_split('//u', '۰۱۲۳۴۵۶۷۸۹', -1, PREG_SPLIT_NO_EMPTY)));
        $this->send($a, $persian, false);
        $this->assertSame('completed', $session->fresh()->getRawOriginal('status'));
        $this->assertStringContainsString('برنده شدی', $this->delivered($a));
        $this->assertStringContainsString('امتیاز رقابتی', $this->delivered($a));
        $this->assertSame('guess_number', $session->fresh()->getRawOriginal('game_type'));
    }

    public function test_public_profile_preserves_user_text_and_localizes_seeded_values(): void
    {
        $u = $this->user('Untranslated Name');
        $text = PublicProfile::fromProfile($u->profile)->text();
        $this->assertStringContainsString('Untranslated Name', $text);
        $this->assertStringContainsString('جنسیت: مرد', $text);
        $this->assertStringNotContainsString('Gender:', $text);
        $this->assertStringNotContainsString('Tehran', $text);
    }

    public static function resources(): array
    {
        return [['users', 'کاربران'], ['reports', 'گزارش‌ها'], ['event-reports', 'گزارش‌های رویداد'], ['blocks', 'مسدودی‌ها'], ['restrictions', 'محدودیت‌ها'], ['wallets', 'کیف پول‌ها'], ['transactions', 'تراکنش‌ها'], ['feature-pricings', 'هزینهٔ قابلیت‌ها'], ['coin-packages', 'بسته‌های سکه'], ['purchase-orders', 'سفارش‌های خرید'], ['gold-memberships', 'عضویت‌های طلایی'], ['events', 'رویدادها'], ['game-sessions', 'نشست‌های بازی'], ['quizzes', 'سؤال‌های مسابقه'], ['this-or-thats', 'سؤال‌های'], ['audit-logs', 'سوابق عملیات مدیران'], ['badges', 'نشان‌ها'], ['economy-settings', 'تنظیمات اقتصاد'], ['gold-settings', 'تنظیمات عضویت طلایی'], ['game-controls', 'تنظیمات بازی‌ها']];
    }

    #[DataProvider('resources')]
    public function test_admin_resources_navigation_and_rtl_are_persian(string $route, string $label): void
    {
        $admin = AdminUser::create(['name' => 'مدیر آزمایشی', 'email' => 'fa@example.test', 'password' => 'Test-only-password-123', 'is_active' => true, 'role' => 'super_admin']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $response = $this->actingAs($admin, 'admin')->get('/admin/'.$route);
        $response->assertOk()->assertSee($label)->assertSee('dir="rtl"', false)->assertSee('lang="fa"', false)->assertSee('persian-admin.css', false)->assertDontSee('fonts.googleapis.com', false)->assertDontSee('fonts.bunny.net', false);
        preg_match_all('/<th\b[^>]*>(.*?)<\/th>/s', $response->getContent(), $headers);
        foreach ($headers[1] as $header) {
            $label = trim(html_entity_decode(strip_tags($header)));
            if ($label !== '') {
                $this->assertDoesNotMatchRegularExpression('/[A-Za-z_]/u', $label);
            }
        }
    }

    public function test_admin_validation_actions_and_login_are_persian(): void
    {
        $this->get('/admin/login')->assertOk()->assertSee('ورود')->assertSee('ایمیل')->assertSee('رمز')->assertSee('dir="rtl"', false);
        $this->assertSame('رفتن به محتوای اصلی', __('filament-panels::layout.skip_to_content.label'));
        $this->assertSame('مسیر صفحه', __('filament::components/breadcrumbs.label'));
        $this->assertSame('در حال بارگذاری…', __('filament::components/loading-section.label'));
        $this->assertSame('15 نتیجه', trans_choice('filament-tables::table.result_count', 15, ['count' => 15]));
        $error = Validator::make(['email' => 'bad'], ['email' => 'required|email'])->errors()->first();
        $this->assertPersian($error);
        $this->assertSame('افزایش سکه', AdminActions::make('Credit coins', 'finance', fn () => null)->getLabel());
        $this->assertSame('Credit coins', AdminActions::make('Credit coins', 'finance', fn () => null)->getName());
        $this->assertSame('admin', Filament::getPanel('admin')->getAuthGuard());
        $this->assertSame(AdminUser::class, config('auth.providers.admins.model'));
    }

    public static function playableGames(): array
    {
        return [['rock_paper_scissors'], ['speed_quiz'], ['this_or_that'], ['guess_interest']];
    }

    #[DataProvider('playableGames')]
    public function test_game_screens_translate_options_without_changing_answer_codes(string $code): void
    {
        $a = $this->user('سارا');
        $b = $this->user('علی');
        $type = GameType::from($code);
        $g = app(GameService::class);
        $session = $g->invite($a, $b, $type);
        $g->accept($b, $session);
        $this->send($a, 'd:0:game_open_'.$session->id);
        $messages = collect($this->telegram->sent)->filter(fn ($m) => ($m['parameters']['chat_id'] ?? null) === $a->telegram_user_id && isset($m['parameters']['text']));
        $this->assertNotEmpty($messages->all());
        foreach ($messages as $message) {
            $this->assertPersian($message['parameters']['text']);
            foreach ($message['parameters']['reply_markup']['inline_keyboard'] ?? [] as $row) {
                foreach ($row as $button) {
                    $this->assertDoesNotMatchRegularExpression('/[A-Za-z]/', $button['text']);
                    $this->assertMatchesRegularExpression('/^d:[0-9]+:[a-z0-9_]+$/', $button['callback_data']);
                }
            }
        }
        $this->assertSame($code, $session->fresh()->getRawOriginal('game_type'));
    }

    public function test_daily_challenge_persian_answer_still_awards_once(): void
    {
        $u = $this->user('سارا');
        $this->send($u, 'd:0:game_daily');
        $screen = collect($this->telegram->sent)->last(fn ($m) => isset($m['parameters']['reply_markup']['inline_keyboard'][0][0]['callback_data']));
        $this->assertPersian($screen['parameters']['text']);
        $callback = $screen['parameters']['reply_markup']['inline_keyboard'][0][0]['callback_data'];
        $this->send($u, $callback);
        $xp = app(GameService::class)->stats($u)->xp;
        $this->send($u, $callback);
        $this->assertSame($xp, app(GameService::class)->stats($u)->xp);
        $this->assertStringContainsString('چالش روزانه', $this->delivered($u));
    }

    public function test_persian_catalog_has_no_encoding_damage(): void
    {
        $catalog = json_decode(file_get_contents(lang_path('fa.json')), true, flags: JSON_THROW_ON_ERROR);
        foreach ($catalog as $english => $persian) {
            $this->assertNotSame($english, $persian);
            $this->assertStringNotContainsString('???', $persian);
            $this->assertStringNotContainsString('�', $persian);
        }
        $this->assertSame('wOF2', substr(file_get_contents(public_path('fonts/vazirmatn/Vazirmatn-Variable.woff2')), 0, 4));
    }

    public function test_dates_digits_and_fallback_do_not_change_storage(): void
    {
        $this->assertSame('2026/09/19 03:30 (میلادی)', Presentation::date('2026-09-19 00:00:00 UTC'));
        $this->assertSame('12345', Presentation::asciiDigits('۱۲۳٤٥'));
        $this->assertSame('en', config('app.fallback_locale'));
        $this->assertSame('UTC', config('app.timezone'));
        $this->assertSame('Custom authored content', Presentation::label('Custom authored content'));
        $this->assertPersian(Presentation::error('Unknown internal error with secret context'));
    }
}
