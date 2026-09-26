<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Concerns\InteractsWithGuessGames;
use Tests\TestCase;

class GameMatchmakingConcurrencyTest extends TestCase
{
    use DatabaseMigrations, InteractsWithGuessGames;

    public function test_simultaneous_workers_create_one_waiting_entry_and_one_match(): void
    {
        $this->bootGameTests();
        $a = $this->user('A');
        $b = $this->user('B');
        $this->race([$a->id, $a->id, $a->id]);
        $this->assertSame(1, DB::table('game_matchmaking_queue')->where('status', 'waiting')->count());
        $this->race([$a->id, $b->id, $b->id]);
        $this->assertDatabaseCount('game_sessions', 1);
        $this->assertSame(2, DB::table('game_matchmaking_queue')->where('status', 'matched')->count());
        $this->assertSame(0, DB::table('game_matchmaking_queue')->where('status', 'waiting')->count());
        $this->assertDatabaseCount('coin_transactions', 0);
    }

    private function race(array $ids): void
    {
        $code = 'require "vendor/autoload.php";$a=require "bootstrap/app.php";$a->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();app(App\\Domain\\Games\\GameMatchmakingService::class)->search(App\\Domain\\Users\\User::findOrFail((int)$argv[1]),App\\Domain\\Games\\GameType::RockPaperScissors,"male");';
        $processes = [];
        foreach ($ids as $id) {
            $process = new Process([PHP_BINARY, '-r', $code, (string) $id], base_path(), ['APP_ENV' => 'testing', 'DB_DATABASE' => 'bot2_test', 'QUEUE_CONNECTION' => 'database']);
            $process->setTimeout(30);
            $process->start();
            $processes[] = $process;
        }
        foreach ($processes as $process) {
            $process->wait();
            $this->assertSame(0, $process->getExitCode(), 'Concurrent matcher failed: '.$process->getErrorOutput());
        }
    }
}
