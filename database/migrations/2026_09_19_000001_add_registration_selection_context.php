<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('registration_states', fn (Blueprint $table) => $table->json('selection_context')->nullable());
    }

    public function down(): void
    {
        Schema::table('registration_states', fn (Blueprint $table) => $table->dropColumn('selection_context'));
    }
};
