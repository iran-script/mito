<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('interaction_states', function (Blueprint $t) {
            $t->string('bulk_mode')->nullable();
            $t->json('bulk_selection')->nullable();
            $t->json('bulk_context')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('interaction_states', function (Blueprint $t) {
            $t->dropColumn(['bulk_mode', 'bulk_selection', 'bulk_context']);
        });
    }
};
