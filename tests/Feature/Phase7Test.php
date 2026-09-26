<?php

namespace Tests\Feature;

use App\Domain\Games\GameService;
use App\Domain\Games\GameStatus;
use App\Domain\Games\GameType;
use App\Domain\Profiles\City;
use App\Domain\Profiles\Gender;
use App\Domain\Profiles\Profile;
use App\Domain\Profiles\ProfileStatus;
use App\Domain\Profiles\RegistrationState;
use App\Domain\Telegram\DiscoveryInteraction;
use App\Domain\Telegram\IncomingUpdate;
use App\Domain\Users\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Phase7Test extends TestCase
{
    use RefreshDatabase;

    private City $city;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->city = City::first();
    }

    private function user(string $name): User
    {
        $u = User::create(['telegram_user_id' => random_int(1000000, 9999999), 'last_activity_at' => now()]);
        Profile::create(['user_id' => $u->id, 'display_name' => $name, 'birth_date' => '2000-01-01', 'gender' => Gender::Male, 'city_id' => $this->city->id, 'status' => ProfileStatus::Active, 'profile_completed_at' => now()]);

        return $u->fresh('profile');
    }

    public function test_invite_accept_and_duplicate_answer_are_idempotent(): void
    {
        $a = $this->user('A');
        $b = $this->user('B');
        $service = app(GameService::class);
        $session = $service->invite($a, $b, GameType::RockPaperScissors);
        $service->accept($b, $session);
        $first = $service->answer($a, $session, 'rock');
        $duplicate = $service->answer($a, $session, 'paper');
        $this->assertFalse($first['completed']);
        $this->assertTrue($duplicate['duplicate']);
        $this->assertSame('active', $session->fresh()->status->value);
    }

    public function test_rps_completion_awards_stats_once_and_games_are_free(): void
    {
        $a = $this->user('A');
        $b = $this->user('B');
        $service = app(GameService::class);
        $session = $service->invite($a, $b, GameType::RockPaperScissors);
        $service->accept($b, $session);
        for ($i = 0; $i < 2; $i++) {
            $service->answer($a, $session->fresh(), 'rock');
            $service->answer($b, $session->fresh(), 'scissors');
        }
        $this->assertSame(GameStatus::Completed, $session->fresh()->status);
        $this->assertSame(1, DB::table('game_player_stats')->where('user_id', $a->id)->value('games_played'));
        $this->assertSame(0, DB::table('coin_transactions')->where('user_id', $a->id)->count());
    }

    public function test_games_menu_is_available_and_does_not_expose_identity(): void
    {
        $a = $this->user('A');
        $state = RegistrationState::create(['user_id' => $a->id, 'step' => 'complete']);
        $update = new IncomingUpdate(['update_id' => 1, 'callback_query' => ['id' => 'x', 'from' => ['id' => $a->telegram_user_id, 'is_bot' => false, 'first_name' => 'A'], 'message' => ['message_id' => 1, 'chat' => ['id' => $a->telegram_user_id, 'type' => 'private']], 'data' => 'd:0:games']]);
        $result = app(DiscoveryInteraction::class)->handle($a, $update, $state);
        $this->assertSame(__('Choose one of these games:'), $result[0]['parameters']['text']);
        $this->assertStringNotContainsString((string) $a->telegram_user_id, json_encode($result));
    }
}
