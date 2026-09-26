<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->string('telegram_cleanup_status')->nullable();
            $table->foreignId('telegram_cleanup_requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('telegram_cleanup_requested_at')->nullable();
            $table->timestampTz('telegram_cleanup_completed_at')->nullable();
        });
        Schema::create('conversation_telegram_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('chat_message_id')->nullable()->constrained('chat_messages')->nullOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->bigInteger('telegram_chat_id');
            $table->bigInteger('telegram_message_id');
            $table->string('direction', 16);
            $table->string('delete_status', 16)->default('pending');
            $table->timestampTz('deleted_at')->nullable();
            $table->timestampsTz();
            $table->unique(['telegram_chat_id', 'telegram_message_id']);
            $table->index(['conversation_id', 'delete_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_telegram_messages');
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('telegram_cleanup_requested_by');
            $table->dropColumn(['telegram_cleanup_status', 'telegram_cleanup_requested_at', 'telegram_cleanup_completed_at']);
        });
    }
};
