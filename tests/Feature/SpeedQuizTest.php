<?php

namespace Tests\Feature;

use App\Domain\Games\GameService;
use App\Domain\Games\GameStatus;
use App\Domain\Games\GameType;
use App\Domain\Games\SpeedQuizService;
use App\Domain\Profiles\City;
use App\Domain\Profiles\Gender;
use App\Domain\Profiles\Profile;
use App\Domain\Profiles\ProfileStatus;
use App\Domain\Users\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SpeedQuizTest extends TestCase
{
    use RefreshDatabase;

    private City $city;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        DB::table('coin_feature_prices')->where('feature_code', 'game_invitation')->update(['coin_cost' => 0]);
        $this->city = City::first();
    }

    private function user(string $name): User
    {
        $u = User::create(['telegram_user_id' => random_int(1000000, 9999999), 'last_activity_at' => now()]);
        Profile::create(['user_id' => $u->id, 'display_name' => $name, 'birth_date' => '2000-01-01', 'gender' => Gender::Male, 'city_id' => $this->city->id, 'status' => ProfileStatus::Active, 'profile_completed_at' => now()]);

        return $u->fresh('profile');
    }

    private function speedSession(): array
    {
        $a = $this->user('A');
        $b = $this->user('B');
        $g = app(GameService::class);
        $s = $g->invite($a, $b, GameType::SpeedQuiz);
        $g->accept($b, $s);
        app(SpeedQuizService::class)->start($s);

        return [$a, $b, $s->fresh()];
    }

    public function test_start_assigns_same_authoritative_ten_question_order(): void
    {
        [$a, $b, $s] = $this->speedSession();
        $this->assertCount(10, $s->state['question_ids']);
        $this->assertSame($s->state['question_ids'], $s->fresh()->state['question_ids']);
        $this->assertNotNull(app(SpeedQuizService::class)->question($s));
    }

    public function test_correct_answer_scores_and_duplicate_does_not_change_it(): void
    {
        [$a, $b, $s] = $this->speedSession();
        $q = app(SpeedQuizService::class)->question($s);
        $correct = DB::table('game_rounds')->where('game_session_id', $s->id)->value('prompt');
        $correct = json_decode($correct, true)['correct_option'];
        $service = app(SpeedQuizService::class);
        $one = $service->answer($a, $s, $correct);
        $duplicate = $service->answer($a, $s->fresh(), $correct);
        $this->assertGreaterThan(0, $one['score']);
        $this->assertTrue($duplicate['duplicate']);
    }

    public function test_wrong_answer_scores_zero_and_invalid_callback_cannot_fake_correctness(): void
    {
        [$a, $b, $s] = $this->speedSession();
        $q = app(SpeedQuizService::class)->question($s);
        $correct = json_decode(DB::table('game_rounds')->where('game_session_id', $s->id)->value('prompt'), true)['correct_option'];
        $wrong = collect($q['options'])->first(fn ($o) => $o !== $correct);
        $this->assertSame(0, app(SpeedQuizService::class)->answer($a, $s, $wrong)['score']);
        $this->expectException(\DomainException::class);
        app(SpeedQuizService::class)->answer($b, $s, 'fake-score');
    }

    public function test_server_latency_controls_bonus(): void
    {
        [$a, $b, $s] = $this->speedSession();
        $q = app(SpeedQuizService::class)->question($s);
        $correct = json_decode(DB::table('game_rounds')->where('game_session_id', $s->id)->value('prompt'), true)['correct_option'];
        $service = app(SpeedQuizService::class);
        $fast = $service->answer($a, $s, $correct, new \DateTimeImmutable('now'));
        $this->assertGreaterThanOrEqual(100, $fast['score']);
    }

    public function test_both_answers_advance_exactly_once(): void
    {
        [$a, $b, $s] = $this->speedSession();
        $service = app(SpeedQuizService::class);
        $q = $service->question($s);
        $service->answer($a, $s, $q['options'][0]);
        $resolved = $service->answer($b, $s->fresh(), $q['options'][1]);
        $this->assertTrue($resolved['resolved']);
        $this->assertFalse($resolved['completed']);
        $this->assertSame(2, $s->fresh()->current_round);
    }

    public function test_ten_questions_complete_and_rewards_once(): void
    {
        [$a, $b, $s] = $this->speedSession();
        $service = app(SpeedQuizService::class);
        for ($i = 0; $i < 10; $i++) {
            $current = $s->fresh();
            $q = $service->question($current);
            $service->answer($a, $current, $q['options'][0]);
            $result = $service->answer($b, $current->fresh(), $q['options'][1]);
        } $done = $s->fresh();
        $this->assertSame(GameStatus::Completed, $done->status);
        $this->assertSame(1, DB::table('game_player_stats')->where('user_id', $a->id)->value('games_played'));
        $this->assertSame(2, DB::table('game_rating_events')->count());
        $this->assertSame(1, DB::table('game_player_stats')->where('user_id', $a->id)->value('games_played'));
    }

    public function test_identity_fields_are_not_in_question_payload(): void
    {
        [$a, $b, $s] = $this->speedSession();
        $payload = json_encode(app(SpeedQuizService::class)->question($s));
        $this->assertStringNotContainsString((string) $a->telegram_user_id, $payload);
        $this->assertStringNotContainsString((string) $b->telegram_user_id, $payload);
    }
}
