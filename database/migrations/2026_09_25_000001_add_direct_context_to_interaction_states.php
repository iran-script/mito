<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('interaction_states', fn (Blueprint $table) => $table->json('direct_context')->nullable());
    }

    public function down(): void
    {
        Schema::table('interaction_states', fn (Blueprint $table) => $table->dropColumn('direct_context'));
    }
};
