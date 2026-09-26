<?php

namespace Tests\Feature;

use App\Domain\Games\GameService;
use App\Domain\Games\GameStatus;
use App\Domain\Games\GameType;
use App\Domain\Games\GuessInterestService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithGuessGames;
use Tests\TestCase;

class GuessGamesConcurrencyTest extends TestCase
{
    use DatabaseMigrations, InteractsWithGuessGames;

    private GameType $gameType;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootGameTests();
    }

    private function type(): GameType
    {
        return $this->gameType;
    }

    private function race(int $session, int $user, string $guess, bool $interest): array
    {
        $code = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$s = App\Domain\Games\GameSession::findOrFail((int) $argv[1]);
$u = App\Domain\Users\User::findOrFail((int) $argv[2]);
while (microtime(true) < (float) $argv[5]) { usleep(1000); }
try {
    $r = $argv[4] === 'interest'
        ? app(App\Domain\Games\GuessInterestService::class)->answer($u, $s, $argv[3], 2)
        : app(App\Domain\Games\GuessNumberService::class)->guess($u, $s, $argv[3], $argv[6]);
    echo json_encode($r);
} catch (DomainException $e) { echo json_encode(['rejected' => true]); }
PHP;
        $db = config('database.connections.pgsql');
        $env = array_merge(getenv(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_HOST' => (string) $db['host'], 'DB_PORT' => (string) $db['port'], 'DB_DATABASE' => (string) $db['database'], 'DB_USERNAME' => (string) $db['username'], 'DB_PASSWORD' => (string) $db['password'], 'QUEUE_CONNECTION' => 'database', 'CACHE_STORE' => 'array']);
        $processes = [];
        $start = (string) (microtime(true) + 2);
        for ($i = 0; $i < 2; $i++) {
            $process = proc_open([PHP_BINARY, '-r', $code, (string) $session, (string) $user, $guess, $interest ? 'interest' : 'number', $start, 'race-'.$i], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);
            $this->assertIsResource($process);
            fclose($pipes[0]);
            $processes[] = [$process, $pipes];
        }
        $results = [];
        foreach ($processes as [$process, $pipes]) {
            $out = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($process), $error);
            $results[] = json_decode($out, true, 512, JSON_THROW_ON_ERROR);
        }

        return $results;
    }

    public function test_guess_interest_simultaneous_final_answers_settle_once(): void
    {
        $this->gameType = GameType::GuessInterest;
        config(['guess_interest.questions_per_player' => 1]);
        [$a, $b, $s] = $this->game();
        app(GuessInterestService::class)->answer($a, $s, 0);
        $p = json_decode(DB::table('game_rounds')->where('game_session_id', $s->id)->where('round_number', 2)->value('prompt'), true);
        $results = $this->race($s->id, $b->id, $p['answer'], true);
        $this->assertSame(1, collect($results)->where('duplicate', false)->count());
        $this->assertSame(GameStatus::Completed, $s->fresh()->status);
        $this->assertDatabaseCount('game_answers', 2);
        foreach ([$a, $b] as $u) {
            $this->assertSame(1, app(GameService::class)->stats($u)->games_played);
        }
        $this->assertSame(17, app(GameService::class)->stats($b)->xp);
        $this->assertDatabaseCount('game_rating_events', 0);
    }

    public function test_guess_number_simultaneous_correct_guesses_settle_once(): void
    {
        $this->gameType = GameType::GuessNumber;
        [$a, $b, $s] = $this->game();
        $results = $this->race($s->id, $a->id, (string) $s->state['gn_secret'], false);
        $this->assertSame(1, collect($results)->where('completed', true)->count());
        $this->assertSame(1, collect($results)->where('rejected', true)->count());
        $this->assertSame(GameStatus::Completed, $s->fresh()->status);
        $this->assertSame(25, app(GameService::class)->stats($a)->xp);
        $this->assertSame(10, app(GameService::class)->stats($b)->xp);
        foreach ([$a, $b] as $u) {
            $this->assertSame(1, app(GameService::class)->stats($u)->games_played);
        }
        $this->assertDatabaseCount('game_rating_events', 1);
        $this->assertDatabaseCount('coin_transactions', 0);
    }
}
