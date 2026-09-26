<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['telegram_updates', 'telegram_outbox'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($name): void {
                $table->string('status', 20)->default($name === 'telegram_updates' ? 'received' : 'pending');
                $table->unsignedInteger('attempt_count')->default(0);
                $table->string('last_error_class')->nullable();
                $table->timestampTz('failed_at')->nullable()->index();
            });
        }
        DB::table('telegram_updates')->whereNotNull('processed_at')->update(['status' => 'processed']);
        DB::table('telegram_outbox')->whereNotNull('sent_at')->update(['status' => 'sent']);
    }

    public function down(): void
    {
        foreach (['telegram_updates', 'telegram_outbox'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn(['status', 'attempt_count', 'last_error_class', 'failed_at']));
        }
    }
};
