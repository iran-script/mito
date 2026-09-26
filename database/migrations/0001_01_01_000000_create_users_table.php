<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('telegram_user_id')->unique();
            $table->string('telegram_username')->nullable();
            $table->string('telegram_first_name')->nullable();
            $table->string('telegram_last_name')->nullable();
            $table->string('telegram_language_code', 35)->nullable();
            $table->boolean('is_bot')->default(false);
            $table->enum('status', ['active', 'suspended', 'banned', 'deleted'])->default('active');
            $table->timestampTz('last_activity_at')->nullable();
            $table->timestampTz('registered_at')->nullable();
            $table->timestampsTz();
            $table->index(['status', 'last_activity_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
