<?php

namespace Tests\Feature;

use App\Domain\Admin\AdminUser;
use App\Domain\Users\User;
use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Actual HTTP requests, cookies, CSRF and independent application lifecycles. */
class AdminLoginHttpTest extends TestCase
{
    use DatabaseMigrations;

    private ?Process $server = null;

    private Client $browser;

    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = AdminUser::create(['name' => 'HTTP Operator', 'email' => 'http-admin@example.test', 'password' => 'Http-test-password-123!', 'is_active' => true, 'role' => 'super_admin']);
        $url = getenv('ADMIN_HTTP_TEST_URL');
        if (! $url) {
            $socket = stream_socket_server('tcp://127.0.0.1:0');
            $address = stream_socket_get_name($socket, false);
            fclose($socket);
            $url = 'http://'.$address;
            $db = config('database.connections.pgsql');
            $this->server = new Process([PHP_BINARY, '-d', 'extension=intl', '-S', $address, base_path('vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')], public_path(), [
                'APP_LOCALE' => 'fa', 'APP_ENV' => 'local', 'APP_DEBUG' => 'false', 'APP_KEY' => config('app.key'), 'APP_URL' => $url,
                'SESSION_DRIVER' => getenv('ADMIN_HTTP_TEST_DRIVER') ?: 'database', 'SESSION_SECURE_COOKIE' => 'false',
                'DB_CONNECTION' => 'pgsql', 'DB_HOST' => $db['host'], 'DB_PORT' => (string) $db['port'],
                'DB_DATABASE' => $db['database'], 'DB_USERNAME' => $db['username'], 'DB_PASSWORD' => $db['password'],
                'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'database',
            ]);
            $this->server->start();
            $ready = false;
            for ($attempt = 0; $attempt < 100; $attempt++) {
                $connection = @stream_socket_client('tcp://'.$address, $errno, $error, 0.1);
                if ($connection) {
                    fclose($connection);
                    $ready = true;
                    break;
                }
                usleep(100000);
            }
            $this->assertTrue($ready, 'HTTP server failed to start');
        }
        $this->browser = new Client(['base_uri' => $url, 'cookies' => new CookieJar, 'http_errors' => false, 'allow_redirects' => false, 'timeout' => 30]);
    }

    protected function tearDown(): void
    {
        $this->server?->stop();
        parent::tearDown();
    }

    private function login(string $password = 'Http-test-password-123!', string $email = 'http-admin@example.test'): array
    {
        $page = $this->browser->get('/admin/login');
        $this->assertSame(200, $page->getStatusCode());
        $html = (string) $page->getBody();
        preg_match('/wire:snapshot="([^"]+)"/', $html, $snapshot);
        preg_match('/data-csrf="([^"]+)"/', $html, $csrf);
        preg_match('/data-update-uri="([^"]+)"/', $html, $uri);
        $this->assertNotEmpty($snapshot);
        $response = $this->browser->post(html_entity_decode($uri[1]), ['json' => [
            '_token' => html_entity_decode($csrf[1]),
            'components' => [[
                'snapshot' => html_entity_decode($snapshot[1], ENT_QUOTES),
                'updates' => ['data.email' => $email, 'data.password' => $password],
                'calls' => [['path' => '', 'method' => 'authenticate', 'params' => []]],
            ]],
        ], 'headers' => ['X-Livewire' => 'true', 'Accept' => 'application/json']]);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function assertGuestDashboard(): void
    {
        $response = $this->browser->get('/admin');
        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringEndsWith('/admin/login', $response->getHeaderLine('Location'));
    }

    public function test_guest_sees_login_and_protected_dashboard_redirects(): void
    {
        $response = $this->browser->get('/admin/login');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('lang="fa"', (string) $response->getBody());
        $this->assertStringContainsString('dir="rtl"', (string) $response->getBody());
        $this->assertStringContainsString('ورود', (string) $response->getBody());
        $this->assertGuestDashboard();
        $this->assertSame(404, $this->browser->get('/admin/register')->getStatusCode());
    }

    public function test_active_admin_logs_in_and_reaches_dashboard_on_separate_request(): void
    {
        $result = $this->login();
        $this->assertStringEndsWith('/admin', $result['components'][0]['effects']['redirect']);
        $response = $this->browser->get('/admin');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('HTTP Operator', (string) $response->getBody());
        $this->assertTrue(DB::table('sessions')->where('user_id', $this->admin->id)->exists());
    }

    public function test_wrong_password_is_rejected(): void
    {
        $result = $this->login('Incorrect-password');
        $this->assertArrayNotHasKey('redirect', $result['components'][0]['effects']);
        $this->assertGuestDashboard();
    }

    public function test_inactive_admin_with_valid_password_is_rejected(): void
    {
        $this->admin->update(['is_active' => false]);
        $result = $this->login();
        $this->assertArrayNotHasKey('redirect', $result['components'][0]['effects']);
        $this->assertGuestDashboard();
    }

    public function test_logout_invalidates_persistent_admin_session(): void
    {
        $this->login();
        $page = $this->browser->get('/admin');
        $this->assertSame(200, $page->getStatusCode());
        preg_match('/name="csrf-token" content="([^"]+)"/', (string) $page->getBody(), $csrf);
        $this->assertNotEmpty($csrf);
        $response = $this->browser->post('/admin/logout', ['form_params' => ['_token' => $csrf[1]]]);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertGuestDashboard();
    }

    public function test_telegram_user_cannot_login_as_admin(): void
    {
        $user = User::create(['telegram_user_id' => 987654321]);
        $this->assertNotInstanceOf(Authenticatable::class, $user);
        $result = $this->login('Http-test-password-123!', 'telegram-user@example.test');
        $this->assertArrayNotHasKey('redirect', $result['components'][0]['effects']);
        $this->assertGuestDashboard();
    }

    public function test_admin_guard_provider_contract_and_access_checks_are_dedicated(): void
    {
        $this->assertSame('admin', Filament::getPanel('admin')->getAuthGuard());
        $this->assertSame('admins', config('auth.guards.admin.provider'));
        $this->assertSame(AdminUser::class, config('auth.providers.admins.model'));
        $this->assertInstanceOf(FilamentUser::class, $this->admin);
        $this->assertTrue($this->admin->canAccessPanel(Filament::getPanel('admin')));
        $this->admin->update(['is_active' => false]);
        $this->assertFalse($this->admin->canAccessPanel(Filament::getPanel('admin')));
    }

    public function test_deactivated_admin_loses_existing_session_access(): void
    {
        $this->login();
        $this->assertSame(200, $this->browser->get('/admin')->getStatusCode());
        $this->admin->update(['is_active' => false]);
        $this->assertSame(403, $this->browser->get('/admin')->getStatusCode());
    }

    public function test_login_assets_are_served_and_csrf_remains_enabled(): void
    {
        $html = (string) $this->browser->get('/admin/login')->getBody();
        preg_match_all('/(?:src|href)="([^"]+\.(?:js|css)(?:\?[^"]*)?)"/', $html, $matches);
        $this->assertNotEmpty($matches[1]);
        foreach ($matches[1] as $asset) {
            $asset = html_entity_decode($asset);
            $path = parse_url($asset, PHP_URL_PATH).(parse_url($asset, PHP_URL_QUERY) ? '?'.parse_url($asset, PHP_URL_QUERY) : '');
            $response = $this->browser->get($path);
            $this->assertSame(200, $response->getStatusCode(), $path);
            $this->assertStringNotContainsString('text/html', $response->getHeaderLine('Content-Type'), $path);
        }
        $this->login();
        $this->assertSame(419, $this->browser->post('/admin/logout')->getStatusCode());
    }
}
