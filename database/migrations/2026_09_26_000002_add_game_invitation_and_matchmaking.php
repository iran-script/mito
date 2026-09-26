<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_sessions', function (Blueprint $table): void {
            $table->string('origin', 30)->default('direct_invitation')->after('game_type');
            $table->index(['origin', 'status']);
        });

        Schema::create('game_matchmaking_queue', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('game_type', 40);
            $table->string('desired_gender', 20)->nullable();
            $table->string('status', 20)->default('waiting');
            $table->timestampTz('started_at');
            $table->timestampTz('expires_at');
            $table->timestampTz('matched_at')->nullable();
            $table->foreignId('matched_session_id')->nullable()->constrained('game_sessions')->nullOnDelete();
            $table->timestampsTz();
            $table->index(['status', 'game_type', 'expires_at']);
            $table->unique(['user_id', 'status']);
        });

        DB::table('coin_feature_prices')->updateOrInsert(
            ['feature_code' => 'game_invitation'],
            ['coin_cost' => 2, 'is_active' => true, 'updated_at' => now(), 'created_at' => now()]
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('game_matchmaking_queue');
        Schema::table('game_sessions', function (Blueprint $table): void {
            $table->dropIndex(['origin', 'status']);
            $table->dropColumn('origin');
        });
        DB::table('coin_feature_prices')->where('feature_code', 'game_invitation')->delete();
    }
};
