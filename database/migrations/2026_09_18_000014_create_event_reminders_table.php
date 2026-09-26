<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('reminder_type', 20);
            $table->timestampTz('sent_at');
            $table->unique(['event_id', 'user_id', 'reminder_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_reminders');
    }
};
