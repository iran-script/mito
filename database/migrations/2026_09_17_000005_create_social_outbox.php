<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_outbox', function (Blueprint $t) {
            $t->id();
            $t->foreignId('recipient_user_id')->constrained('users')->cascadeOnDelete();
            $t->string('source_type', 32);
            $t->unsignedBigInteger('source_id');
            $t->string('idempotency_key', 128)->unique();
            $t->text('payload');
            $t->timestampTz('sent_at')->nullable();
            $t->timestampsTz();
            $t->index(['recipient_user_id', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_outbox');
    }
};
