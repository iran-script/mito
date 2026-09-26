<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_identity_share_events', function (Blueprint $t) {
            $t->string('requester_username_snapshot')->nullable();
            $t->string('recipient_username_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('telegram_identity_share_events', function (Blueprint $t) {
            $t->dropColumn(['requester_username_snapshot', 'recipient_username_snapshot']);
        });
    }
};
