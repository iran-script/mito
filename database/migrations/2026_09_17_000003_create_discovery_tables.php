<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->timestampTz('location_updated_at')->nullable();
            $table->index(['latitude', 'longitude']);
        });
        DB::statement('ALTER TABLE profiles ADD CONSTRAINT profiles_latitude_check CHECK (latitude IS NULL OR latitude BETWEEN -90 AND 90)');
        DB::statement('ALTER TABLE profiles ADD CONSTRAINT profiles_longitude_check CHECK (longitude IS NULL OR longitude BETWEEN -180 AND 180)');

        Schema::create('user_blocks', function (Blueprint $table) {
            $table->foreignId('blocker_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('blocked_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->primary(['blocker_user_id', 'blocked_user_id']);
            $table->index('blocked_user_id');
        });

        Schema::create('discovery_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requester_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('discovered_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('discovery_type', 32);
            $table->timestampTz('discovered_at')->useCurrent();
            $table->index(['requester_user_id', 'discovery_type', 'discovered_at']);
            $table->index(['discovered_user_id', 'discovered_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discovery_histories');
        Schema::dropIfExists('user_blocks');
        Schema::table('profiles', function (Blueprint $table) {
            $table->dropIndex('profiles_latitude_longitude_index');
            $table->dropColumn(['latitude', 'longitude', 'location_updated_at']);
        });
    }
};
