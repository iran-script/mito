<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('interaction_states', function (Blueprint $table) {
            $table->json('game_context')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('interaction_states', function (Blueprint $table) {
            $table->dropColumn('game_context');
        });
    }
};
