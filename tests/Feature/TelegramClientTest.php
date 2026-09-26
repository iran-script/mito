<?php

namespace Tests\Feature;

use App\Domain\Telegram\HttpTelegramClient;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class TelegramClientTest extends TestCase
{
    public function test_client_uses_central_telegram_endpoint(): void
    {
        config(['telegram.token' => 'test-token']);
        Http::preventStrayRequests();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        (new HttpTelegramClient)->send('sendMessage', ['chat_id' => 123, 'text' => 'Hello']);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.telegram.org/bottest-token/sendMessage' && $request['text'] === 'Hello');
    }

    public function test_transport_errors_do_not_expose_token_or_content(): void
    {
        config(['telegram.token' => 'secret-token']);
        Http::fake(['*' => Http::response(['ok' => false, 'description' => 'private content'], 500)]);
        try {
            (new HttpTelegramClient)->send('sendMessage', ['text' => 'private content']);
            $this->fail('Expected failure');
        } catch (RuntimeException $error) {
            $this->assertStringNotContainsString('secret-token', $error->getMessage());
            $this->assertStringNotContainsString('private content', $error->getMessage());
        }
    }
}
