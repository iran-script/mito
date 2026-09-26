<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('interaction_states', function (Blueprint $t) {
            $t->json('event_context')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('interaction_states', fn (Blueprint $t) => $t->dropColumn('event_context'));
    }
};
