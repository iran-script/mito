<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_updates', function (Blueprint $t) {
            $t->bigInteger('update_id')->primary();
            $t->bigInteger('telegram_user_id')->index();
            $t->text('payload')->nullable();
            $t->timestampTz('processed_at')->nullable()->index();
            $t->timestampsTz();
        });
        Schema::create('telegram_outbox', function (Blueprint $t) {
            $t->id();
            $t->bigInteger('update_id');
            $t->foreign('update_id')->references('update_id')->on('telegram_updates')->cascadeOnDelete();
            $t->smallInteger('sequence');
            $t->unique(['update_id', 'sequence']);
            $t->text('payload')->nullable();
            $t->timestampTz('sent_at')->nullable()->index();
            $t->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_outbox');
        Schema::dropIfExists('telegram_updates');
    }
};
