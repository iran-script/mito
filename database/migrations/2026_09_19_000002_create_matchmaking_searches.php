<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matchmaking_searches', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('gender', 8);
            $table->string('status', 16);
            $table->unsignedInteger('generation')->default(1);
            $table->foreignId('matched_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('started_at');
            $table->timestampTz('expires_at');
            $table->timestampsTz();
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matchmaking_searches');
    }
};
