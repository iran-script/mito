<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provinces', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->timestampsTz();
        });
        Schema::create('cities', function (Blueprint $t) {
            $t->id();
            $t->foreignId('province_id')->constrained()->restrictOnDelete();
            $t->string('name');
            $t->unique(['province_id', 'name']);
            $t->timestampsTz();
        });
        Schema::create('interests', function (Blueprint $t) {
            $t->id();
            $t->string('slug')->unique();
            $t->string('name');
            $t->timestampsTz();
        });
        Schema::create('profiles', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $t->string('display_name', 50)->nullable();
            $t->date('birth_date')->nullable();
            $t->enum('gender', ['male', 'female'])->nullable();
            $t->foreignId('city_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('photo_file_id', 512)->nullable();
            $t->string('voice_file_id', 512)->nullable();
            $t->smallInteger('voice_duration')->nullable();
            $t->text('bio')->nullable();
            $t->enum('status', ['draft', 'active'])->default('draft');
            $t->timestampTz('profile_completed_at')->nullable();
            $t->timestampsTz();
            $t->index(['city_id', 'status', 'gender']);
        });
        DB::statement('ALTER TABLE profiles ADD CONSTRAINT profiles_voice_duration_check CHECK (voice_duration IS NULL OR voice_duration BETWEEN 0 AND 120)');
        DB::statement("ALTER TABLE profiles ADD CONSTRAINT profiles_active_complete_check CHECK (status <> 'active' OR (display_name IS NOT NULL AND birth_date IS NOT NULL AND gender IS NOT NULL AND city_id IS NOT NULL AND profile_completed_at IS NOT NULL))");
        Schema::create('interest_profile', function (Blueprint $t) {
            $t->foreignId('profile_id')->constrained()->cascadeOnDelete();
            $t->foreignId('interest_id')->constrained()->cascadeOnDelete();
            $t->primary(['profile_id', 'interest_id']);
            $t->index('interest_id');
            $t->timestampsTz();
        });
        Schema::create('registration_states', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $t->enum('step', ['name', 'age', 'gender', 'city', 'photo', 'voice', 'interests', 'preview', 'complete'])->default('name');
            $t->unsignedInteger('revision')->default(0);
            $t->timestampsTz();
        });
        // Future precise location belongs in a private, access-controlled table keyed by profile_id.
    }

    public function down(): void
    {
        foreach (['registration_states', 'interest_profile', 'profiles', 'interests', 'cities', 'provinces'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
