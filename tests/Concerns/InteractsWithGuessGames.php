<?php

namespace Tests\Concerns;

use App\Domain\Games\GameService;
use App\Domain\Profiles\City;
use App\Domain\Profiles\Gender;
use App\Domain\Profiles\Interest;
use App\Domain\Profiles\Profile;
use App\Domain\Profiles\ProfileStatus;
use App\Domain\Profiles\RegistrationState;
use App\Domain\Telegram\TelegramClient;
use App\Domain\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\FakeTelegramClient;

trait InteractsWithGuessGames
{
    private FakeTelegramClient $telegram;

    private int $sequence = 8000;

    private function bootGameTests(): void
    {
        $this->seed();
        Http::preventStrayRequests();
        $this->telegram = new FakeTelegramClient;
        $this->app->instance(TelegramClient::class, $this->telegram);
        config(['telegram.webhook_secret' => 'test-secret']);
    }

    private function user(string $name): User
    {
        $u = User::create(['telegram_user_id' => random_int(100000000, 999999999), 'telegram_username' => 'private_'.$name, 'last_activity_at' => now()]);
        $p = Profile::create(['user_id' => $u->id, 'display_name' => $name, 'birth_date' => '2000-01-01', 'gender' => Gender::Male, 'city_id' => City::first()->id, 'status' => ProfileStatus::Active, 'profile_completed_at' => now()]);
        $p->interests()->attach(Interest::orderBy('id')->take(5)->pluck('id'));
        RegistrationState::create(['user_id' => $u->id, 'step' => 'complete']);

        return $u->fresh('profile');
    }

    private function game(bool $accept = true): array
    {
        $a = $this->user('A');
        $b = $this->user('B');
        $s = app(GameService::class)->invite($a, $b, $this->type());
        if ($accept) {
            app(GameService::class)->accept($b, $s);
        }

        return [$a, $b, $s->fresh()];
    }

    private function rejects(callable $action): void
    {
        try {
            $action();
            $this->fail('Expected a domain rejection.');
        } catch (\DomainException $e) {
            $this->assertNotEmpty($e->getMessage());
        }
    }

    private function send(User $user, string $value, bool $callback = true, ?int $id = null): int
    {
        $id ??= ++$this->sequence;
        $sender = ['id' => $user->telegram_user_id, 'is_bot' => false, 'first_name' => 'Private', 'username' => $user->telegram_username];
        $message = ['message_id' => $id, 'chat' => ['id' => $user->telegram_user_id, 'type' => 'private']];
        $payload = ['update_id' => $id] + ($callback ? ['callback_query' => ['id' => 'cb'.$id, 'from' => $sender, 'message' => $message, 'data' => $value]] : ['message' => $message + ['from' => $sender, 'text' => $value]]);
        $this->postJson('/api/telegram/webhook', $payload, ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret'])->assertOk();
        foreach (['telegram', 'telegram-outbound'] as $queue) {
            while ($job = Queue::connection('database')->pop($queue)) {
                $job->fire();
                $job->delete();
            }
        }

        return $id;
    }

    private function assertPrivateMessages(User ...$users): void
    {
        foreach ($this->telegram->sent as $message) {
            $parameters = $message['parameters'];
            unset($parameters['chat_id']);
            $visible = json_encode($parameters);
            foreach ($users as $user) {
                $this->assertStringNotContainsString((string) $user->telegram_user_id, $visible);
                $this->assertStringNotContainsString($user->telegram_username, $visible);
            }
            foreach (['latitude', 'longitude', 'gn_secret', 'gi_used', 'correct_option'] as $private) {
                $this->assertStringNotContainsString($private, $visible);
            }
            foreach ($parameters['reply_markup']['inline_keyboard'] ?? [] as $row) {
                foreach ($row as $button) {
                    $this->assertLessThanOrEqual(64, strlen($button['callback_data']));
                }
            }
        }
        $this->assertSame(0, DB::table('telegram_updates')->whereNull('processed_at')->count());
    }

    private function delivered(User $user): string
    {
        return collect($this->telegram->sent)->filter(fn ($m) => ($m['parameters']['chat_id'] ?? null) === $user->telegram_user_id)->pluck('parameters.text')->implode(' ');
    }
}
