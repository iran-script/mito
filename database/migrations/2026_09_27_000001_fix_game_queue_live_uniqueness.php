<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Historical terminal rows are audit data; only waiting entries are exclusive.
        DB::statement("CREATE UNIQUE INDEX game_matchmaking_queue_one_waiting_per_user ON game_matchmaking_queue (user_id) WHERE status = 'waiting'");
        DB::statement('ALTER TABLE game_matchmaking_queue DROP CONSTRAINT game_matchmaking_queue_user_id_status_unique');
    }

    public function down(): void
    {
        // PostgreSQL rejects rollback if new history cannot satisfy the old constraint.
        // Never delete historical rows to make a schema rollback fit.
        DB::statement('ALTER TABLE game_matchmaking_queue ADD CONSTRAINT game_matchmaking_queue_user_id_status_unique UNIQUE (user_id, status)');
        DB::statement('DROP INDEX game_matchmaking_queue_one_waiting_per_user');
    }
};
