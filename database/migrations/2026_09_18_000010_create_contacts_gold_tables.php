<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_contacts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->foreignId('contact_user_id')->constrained('users')->cascadeOnDelete();
            $t->timestamps();
            $t->unique(['user_id', 'contact_user_id']);
            $t->index(['user_id', 'created_at']);
        });
        Schema::create('memberships', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->string('plan_code', 40);
            $t->timestampTz('starts_at');
            $t->timestampTz('ends_at');
            $t->string('status', 20)->default('active');
            $t->string('source', 40);
            $t->timestamps();
            $t->index(['user_id', 'plan_code', 'ends_at']);
        });
        Schema::create('gold_usage_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->string('action', 40);
            $t->unsignedInteger('recipient_count')->default(1);
            $t->string('idempotency_key')->unique();
            $t->timestampTz('occurred_at')->useCurrent();
            $t->index(['user_id', 'action', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gold_usage_events');
        Schema::dropIfExists('memberships');
        Schema::dropIfExists('user_contacts');
    }
};
