<?php

namespace Tests\Feature;

use App\Domain\Games\GameService;
use App\Domain\Games\GameStatus;
use App\Domain\Games\GameType;
use App\Domain\Games\ThisOrThatService;
use App\Domain\Profiles\City;
use App\Domain\Profiles\Gender;
use App\Domain\Profiles\Profile;
use App\Domain\Profiles\ProfileStatus;
use App\Domain\Users\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ThisOrThatTest extends TestCase
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

    private function game(): array
    {
        $a = $this->user('A');
        $b = $this->user('B');
        $g = app(GameService::class);
        $s = $g->invite($a, $b, GameType::ThisOrThat);
        $g->accept($b, $s);
        app(ThisOrThatService::class)->start($s);

        return [$a, $b, $s->fresh()];
    }

    public function test_fixed_ten_questions_and_private_first_answer(): void
    {
        [$a, $b, $s] = $this->game();
        $service = app(ThisOrThatService::class);
        $q = $service->question($s);
        $this->assertSame(10, $q['total']);
        $one = $service->answer($a, $s, $q['options'][0]);
        $this->assertFalse($one['resolved']);
        $this->assertCount(1, DB::table('game_answers')->get());
    }

    public function test_matching_answers_advance_and_duplicate_is_safe(): void
    {
        [$a, $b, $s] = $this->game();
        $service = app(ThisOrThatService::class);
        $q = $service->question($s);
        $service->answer($a, $s, $q['options'][0]);
        $same = $service->answer($b, $s->fresh(), $q['options'][0]);
        $this->assertTrue($same['resolved']);
        $this->assertFalse($same['completed']);
        $duplicate = $service->answer($b, $s->fresh(), $q['options'][0], 1);
        $this->assertTrue($duplicate['duplicate']);
    }

    public function test_ten_questions_calculate_compatibility_without_rating(): void
    {
        [$a, $b, $s] = $this->game();
        $service = app(ThisOrThatService::class);
        for ($i = 0; $i < 10; $i++) {
            $current = $s->fresh();
            $q = $service->question($current);
            $service->answer($a, $current, $q['options'][0]);
            $result = $service->answer($b, $current->fresh(), $q['options'][($i % 2) === 0 ? 0 : 1]);
        } $done = $s->fresh();
        $this->assertSame(GameStatus::Completed, $done->status);
        $this->assertSame(50, $done->state['compatibility']);
        $this->assertSame(0, DB::table('game_rating_events')->count());
    }

    public function test_social_game_grants_xp_and_does_not_change_wins_or_coins(): void
    {
        [$a, $b, $s] = $this->game();
        $service = app(ThisOrThatService::class);
        for ($i = 0; $i < 10; $i++) {
            $current = $s->fresh();
            $q = $service->question($current);
            $service->answer($a, $current, $q['options'][0]);
            $service->answer($b, $current->fresh(), $q['options'][0]);
        } $stats = DB::table('game_player_stats')->where('user_id', $a->id)->first();
        $this->assertSame(1, $stats->games_played);
        $this->assertSame(0, $stats->wins);
        $this->assertSame(15, $stats->xp);
        $this->assertSame(0, DB::table('coin_transactions')->where('user_id', $a->id)->count());
    }

    public function test_duplicate_completion_does_not_duplicate_xp(): void
    {
        [$a, $b, $s] = $this->game();
        $service = app(ThisOrThatService::class);
        for ($i = 0; $i < 10; $i++) {
            $current = $s->fresh();
            $q = $service->question($current);
            $service->answer($a, $current, $q['options'][0]);
            $service->answer($b, $current->fresh(), $q['options'][0]);
        } $stats = DB::table('game_player_stats')->where('user_id', $a->id)->first();
        $this->assertSame(15, $stats->xp);
    }
}
