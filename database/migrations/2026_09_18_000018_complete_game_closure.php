<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_rating_events', function (Blueprint $t) {
            $t->json('participant_deltas')->nullable();
            $t->index(['created_at', 'user_id']);
        });
        Schema::create('daily_game_questions', function (Blueprint $t) {
            $t->date('challenge_date')->primary();
            $t->json('prompt');
            $t->timestampsTz();
        });
        Schema::create('daily_game_answers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->date('challenge_date');
            $t->unsignedSmallInteger('option_index');
            $t->boolean('is_correct');
            $t->timestampsTz();
            $t->unique(['user_id', 'challenge_date']);
        });
        // Older Guess Number events stored only the winner. Replay the complete
        // local ledger from the initial 1000 rating to recover floor-aware losses.
        $ratings = [];
        foreach (DB::table('game_rating_events as e')->leftJoin('game_sessions as s', 's.id', '=', 'e.game_session_id')->orderBy('e.created_at')->orderBy('e.id')->select('e.*', 's.game_type', 's.state')->cursor() as $e) {
            $ratings[$e->user_id] = (int) $e->score_after;
            if ($e->game_type === 'guess_number') {
                $st = json_decode($e->state ?? '{}', true);
                $loser = $st['gn_result']['loser_id'] ?? null;
                if (! $loser) {
                    $loser = DB::table('game_participants')->where('game_session_id', $e->game_session_id)->where('user_id', '!=', $e->user_id)->value('user_id');
                }
                if ($loser) {
                    $loss = -min(10, $ratings[$loser] ?? 1000);
                    $ratings[$loser] = ($ratings[$loser] ?? 1000) + $loss;
                    DB::table('game_rating_events')->where('id', $e->id)->update(['participant_deltas' => json_encode([$e->user_id => (int) $e->delta, $loser => $loss])]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_game_answers');
        Schema::dropIfExists('daily_game_questions');
        Schema::table('game_rating_events', function (Blueprint $t) {
            $t->dropIndex(['created_at', 'user_id']);
            $t->dropColumn('participant_deltas');
        });
    }
};
