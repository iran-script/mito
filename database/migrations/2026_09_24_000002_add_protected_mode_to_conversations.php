<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->boolean('is_protected')->default(false);
        });
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->boolean('is_protected')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropColumn('is_protected');
        });
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn('is_protected');
        });
    }
};
