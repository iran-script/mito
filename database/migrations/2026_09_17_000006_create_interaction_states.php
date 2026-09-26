<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interaction_states', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $t->string('mode', 24)->default('menu');
            $t->unsignedInteger('revision')->default(0);
            $t->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('direct_recipient_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interaction_states');
    }
};
