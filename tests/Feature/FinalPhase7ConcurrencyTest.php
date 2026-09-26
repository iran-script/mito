<?php

namespace Tests\Feature;

use App\Domain\Games\GameService;
use App\Domain\Games\GameType;
use App\Domain\Games\GuessNumberService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithGuessGames;
use Tests\TestCase;

class FinalPhase7ConcurrencyTest extends TestCase
{
    use DatabaseMigrations, InteractsWithGuessGames;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootGameTests();
        DB::table('coin_feature_prices')->where('feature_code', 'game_invitation')->update(['coin_cost' => 0]);
    }

    private function type(): GameType
    {
        return GameType::GuessNumber;
    }

    public static function actions(): array
    {
        return [['rematch'], ['interest']];
    }

    #[DataProvider('actions')]
    public function test_simultaneous_postgame_actions_are_idempotent(string $action): void
    {
        [$a, $b, $s] = $this->game();
        app(GuessNumberService::class)->guess($a, $s, (string) $s->state['gn_secret'], 'complete');
        $code = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$s = App\Domain\Games\GameSession::findOrFail((int) $argv[1]);
$u = App\Domain\Users\User::findOrFail((int) $argv[2]);
while (microtime(true) < (float) $argv[4]) { usleep(1000); }
$result = app(App\Domain\Games\GameClosureService::class)->{$argv[3]}($u, $s);
echo json_encode($argv[3] === 'rematch' ? $result->id : $result);
PHP;
        $db = config('database.connections.pgsql');
        $env = array_merge(getenv(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_HOST' => (string) $db['host'], 'DB_PORT' => (string) $db['port'], 'DB_DATABASE' => (string) $db['database'], 'DB_USERNAME' => (string) $db['username'], 'DB_PASSWORD' => (string) $db['password'], 'QUEUE_CONNECTION' => 'database', 'CACHE_STORE' => 'array']);
        $processes = [];
        $start = (string) (microtime(true) + 2);
        foreach ([$a, $b] as $u) {
            $process = proc_open([PHP_BINARY, '-r', $code, (string) $s->id, (string) $u->id, $action, $start], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);
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
        if ($action === 'rematch') {
            $this->assertSame($results[0], $results[1]);
            $this->assertDatabaseCount('game_sessions', 2);
        } else {
            sort($results);
            $this->assertSame([false, true], $results);
            $this->assertDatabaseCount('game_social_intents', 2);
            $this->assertSame(2, DB::table('social_outbox')->where('source_type', 'game_mutual')->count());
            $this->assertNotEmpty($s->fresh()->state['mutual_match_at']);
        }
        $this->assertSame(1, app(GameService::class)->stats($a)->games_played);
        $this->assertDatabaseCount('conversations', 0);
        $this->assertDatabaseCount('coin_transactions', 0);
    }
}
