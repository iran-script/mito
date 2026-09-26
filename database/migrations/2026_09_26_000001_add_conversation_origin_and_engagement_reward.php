<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->string('origin', 24)->nullable()->after('status');
            $table->foreignId('chat_request_id')->nullable()->after('origin')->constrained('chat_requests')->nullOnDelete();
            $table->foreignId('accepted_by_user_id')->nullable()->after('chat_request_id')->constrained('users')->nullOnDelete();
            $table->timestampTz('activated_at')->nullable()->after('accepted_by_user_id');
            $table->timestampTz('engagement_rewarded_at')->nullable()->after('activated_at');
            $table->index(['origin', 'engagement_rewarded_at']);
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex(['origin', 'engagement_rewarded_at']);
            $table->dropConstrainedForeignId('accepted_by_user_id');
            $table->dropConstrainedForeignId('chat_request_id');
            $table->dropColumn(['origin', 'activated_at', 'engagement_rewarded_at']);
        });
    }
};
