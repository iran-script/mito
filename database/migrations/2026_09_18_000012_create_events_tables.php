<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_categories', function (Blueprint $t) {
            $t->id();
            $t->string('slug')->unique();
            $t->string('name');
            $t->timestamps();
        });
        Schema::create('events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('creator_user_id')->constrained('users')->cascadeOnDelete();
            $t->string('title', 120);
            $t->text('description')->nullable();
            $t->foreignId('category_id')->constrained('event_categories');
            $t->timestampTz('starts_at');
            $t->timestampTz('ends_at')->nullable();
            $t->unsignedInteger('capacity')->nullable();
            $t->foreignId('city_id')->nullable()->constrained('cities')->nullOnDelete();
            $t->decimal('latitude', 10, 7)->nullable();
            $t->decimal('longitude', 10, 7)->nullable();
            $t->timestampTz('location_updated_at')->nullable();
            $t->string('status', 20)->default('published');
            $t->timestamps();
            $t->index(['status', 'starts_at']);
            $t->index(['category_id', 'starts_at']);
            $t->index(['city_id', 'starts_at']);
        });
        Schema::create('event_participants', function (Blueprint $t) {
            $t->id();
            $t->foreignId('event_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->string('status', 20)->default('joined');
            $t->timestampTz('joined_at')->useCurrent();
            $t->timestampTz('left_at')->nullable();
            $t->timestamps();
            $t->unique(['event_id', 'user_id']);
            $t->index(['user_id', 'status']);
        });
        Schema::create('event_reports', function (Blueprint $t) {
            $t->id();
            $t->foreignId('event_id')->constrained()->cascadeOnDelete();
            $t->foreignId('reporter_user_id')->constrained('users')->cascadeOnDelete();
            $t->string('reason', 40);
            $t->text('description')->nullable();
            $t->string('status', 20)->default('open');
            $t->timestamps();
            $t->unique(['event_id', 'reporter_user_id']);
        });
    }

    public function down(): void
    {
        foreach (['event_reports', 'event_participants', 'events', 'event_categories'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
