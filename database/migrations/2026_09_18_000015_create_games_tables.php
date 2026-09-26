<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_sessions', function (Blueprint $t) {
            $t->id();
            $t->string('game_type', 40);
            $t->string('status', 20)->default('waiting');
            $t->foreignId('created_by')->constrained('users');
            $t->unsignedInteger('current_round')->default(1);
            $t->json('state')->nullable();
            $t->timestampTz('expires_at')->nullable();
            $t->timestampsTz();
            $t->index(['game_type', 'status']);
            $t->index(['status', 'expires_at']);
        });
        Schema::create('game_participants', function (Blueprint $t) {
            $t->id();
            $t->foreignId('game_session_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('score')->default(0);
            $t->unsignedInteger('xp_earned')->default(0);
            $t->timestampsTz();
            $t->unique(['game_session_id', 'user_id']);
            $t->index('user_id');
        });
        Schema::create('game_rounds', function (Blueprint $t) {
            $t->id();
            $t->foreignId('game_session_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('round_number');
            $t->json('prompt')->nullable();
            $t->string('winner', 20)->nullable();
            $t->timestampTz('completed_at')->nullable();
            $t->timestampsTz();
            $t->unique(['game_session_id', 'round_number']);
        });
        Schema::create('game_answers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('game_round_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->text('answer');
            $t->boolean('is_correct')->nullable();
            $t->unsignedInteger('response_ms')->nullable();
            $t->timestampsTz();
            $t->unique(['game_round_id', 'user_id']);
        });
        Schema::create('game_player_stats', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $t->unsignedInteger('games_played')->default(0);
            $t->unsignedInteger('wins')->default(0);
            $t->unsignedInteger('losses')->default(0);
            $t->unsignedInteger('draws')->default(0);
            $t->unsignedInteger('current_streak')->default(0);
            $t->unsignedInteger('best_streak')->default(0);
            $t->unsignedInteger('xp')->default(0);
            $t->integer('competitive_score')->default(1000);
            $t->timestampsTz();
        });
        Schema::create('badges', function (Blueprint $t) {
            $t->id();
            $t->string('code')->unique();
            $t->string('name');
            $t->text('description')->nullable();
            $t->timestampsTz();
        });
        Schema::create('user_badges', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('badge_id')->constrained()->cascadeOnDelete();
            $t->timestampTz('awarded_at');
            $t->unique(['user_id', 'badge_id']);
        });
        Schema::create('quiz_questions', function (Blueprint $t) {
            $t->id();
            $t->text('question');
            $t->json('options');
            $t->string('correct_option', 100);
            $t->string('category', 50)->nullable();
            $t->unsignedSmallInteger('difficulty')->default(1);
            $t->boolean('is_active')->default(true);
            $t->timestampsTz();
        });
        Schema::create('daily_challenge_completions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->date('challenge_date');
            $t->unsignedInteger('xp_awarded')->default(0);
            $t->timestampsTz();
            $t->unique(['user_id', 'challenge_date']);
        });
        Schema::create('game_social_intents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('game_session_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('intent', 30);
            $t->timestampsTz();
            $t->unique(['game_session_id', 'user_id', 'intent']);
        });
    }

    public function down(): void
    {
        foreach (['game_social_intents', 'daily_challenge_completions', 'quiz_questions', 'user_badges', 'badges', 'game_player_stats', 'game_answers', 'game_rounds', 'game_participants', 'game_sessions'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
