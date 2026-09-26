<?php

namespace Tests\Feature;

use App\Domain\Profiles\Profile;
use App\Domain\Profiles\RegistrationState;
use App\Domain\Profiles\RegistrationStep;
use App\Domain\Telegram\Jobs\ProcessUpdate;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

class PersistenceTest extends TestCase
{
    use DatabaseMigrations;

    public function test_registration_survives_new_application_and_webhook_requests(): void
    {
        $payload = ['update_id' => 1, 'message' => ['message_id' => 1, 'from' => ['id' => 123, 'is_bot' => false, 'first_name' => 'Private'], 'chat' => ['id' => 123, 'type' => 'private'], 'text' => '/start']];
        config(['telegram.webhook_secret' => 'test-secret']);
        $this->postJson('/api/telegram/webhook', $payload, ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret'])->assertOk();
        $this->app->call([new ProcessUpdate(1), 'handle']);
        $this->refreshApplication();
        config(['telegram.webhook_secret' => 'test-secret']);
        $payload['update_id'] = 2;
        $payload['message']['message_id'] = 2;
        $payload['message']['text'] = 'Sara';
        $this->postJson('/api/telegram/webhook', $payload, ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret'])->assertOk();
        $this->app->call([new ProcessUpdate(2), 'handle']);
        $this->assertSame('Sara', Profile::first()->display_name);
        $this->assertSame(RegistrationStep::Age, RegistrationState::first()->step);
    }
}
