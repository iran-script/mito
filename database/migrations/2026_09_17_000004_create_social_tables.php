<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('requester_user_id')->constrained('users')->cascadeOnDelete();
            $t->foreignId('recipient_user_id')->constrained('users')->cascadeOnDelete();
            $t->string('status', 16)->default('pending');
            $t->timestampTz('seen_at')->nullable();
            $t->timestampTz('accepted_at')->nullable();
            $t->timestampTz('rejected_at')->nullable();
            $t->timestampTz('cancelled_at')->nullable();
            $t->timestampTz('expires_at')->nullable();
            $t->timestampsTz();
            $t->index(['recipient_user_id', 'status']);
            $t->index(['requester_user_id', 'status']);
            $t->index(['requester_user_id', 'recipient_user_id', 'status']);
        });
        Schema::create('conversations', function (Blueprint $t) {
            $t->id();
            $t->string('status', 16)->default('active');
            $t->unsignedBigInteger('user_low_id');
            $t->unsignedBigInteger('user_high_id');
            $t->timestampsTz();
            $t->unique(['user_low_id', 'user_high_id']);
            $t->index(['status', 'updated_at']);
            $t->foreign('user_low_id')->references('id')->on('users')->cascadeOnDelete();
            $t->foreign('user_high_id')->references('id')->on('users')->cascadeOnDelete();
        });
        Schema::create('conversation_participants', function (Blueprint $t) {
            $t->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->timestampTz('last_read_at')->nullable();
            $t->primary(['conversation_id', 'user_id']);
            $t->index('user_id');
        });
        Schema::create('chat_messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $t->foreignId('sender_user_id')->constrained('users')->cascadeOnDelete();
            $t->string('message_type', 16);
            $t->text('text')->nullable();
            $t->string('telegram_file_id', 512)->nullable();
            $t->timestampTz('sent_at')->useCurrent();
            $t->timestampTz('delivered_at')->nullable();
            $t->timestampTz('read_at')->nullable();
            $t->string('moderation_status', 16)->default('allowed');
            $t->string('idempotency_key', 128)->unique();
            $t->timestampsTz();
            $t->index(['conversation_id', 'sent_at']);
            $t->index(['sender_user_id', 'read_at']);
        });
        Schema::create('direct_messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('sender_user_id')->constrained('users')->cascadeOnDelete();
            $t->foreignId('recipient_user_id')->constrained('users')->cascadeOnDelete();
            $t->string('status', 16)->default('sent');
            $t->text('text');
            $t->timestampTz('seen_at')->nullable();
            $t->string('idempotency_key', 128)->unique();
            $t->timestampsTz();
            $t->index(['recipient_user_id', 'status']);
            $t->index(['sender_user_id', 'created_at']);
        });
        Schema::create('reports', function (Blueprint $t) {
            $t->id();
            $t->foreignId('reporter_user_id')->constrained('users')->cascadeOnDelete();
            $t->foreignId('reported_user_id')->constrained('users')->cascadeOnDelete();
            $t->string('reason', 32);
            $t->text('description')->nullable();
            $t->foreignId('chat_message_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('direct_message_id')->nullable()->constrained()->nullOnDelete();
            $t->string('status', 16)->default('open');
            $t->timestampsTz();
            $t->index(['reported_user_id', 'status']);
            $t->index(['reporter_user_id', 'created_at']);
        });
        DB::statement('ALTER TABLE conversations ADD CONSTRAINT conversations_pair_order CHECK (user_low_id < user_high_id)');
        DB::statement("ALTER TABLE chat_messages ADD CONSTRAINT chat_messages_content_check CHECK ((message_type = 'text' AND text IS NOT NULL AND telegram_file_id IS NULL) OR (message_type IN ('photo','voice') AND telegram_file_id IS NOT NULL))");
    }

    public function down(): void
    {
        foreach (['reports', 'direct_messages', 'chat_messages', 'conversation_participants', 'conversations', 'chat_requests'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
