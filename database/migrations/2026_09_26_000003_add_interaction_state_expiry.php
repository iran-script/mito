<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::table('interaction_states', fn (Blueprint $table) => $table->timestampTz('expires_at')->nullable()->index()); }
    public function down(): void { Schema::table('interaction_states', function (Blueprint $table) { $table->dropIndex(['expires_at']); $table->dropColumn('expires_at'); }); }
};