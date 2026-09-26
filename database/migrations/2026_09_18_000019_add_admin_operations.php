<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_users', fn (Blueprint $t) => $t->string('role', 30)->default('super_admin'));
        Schema::create('admin_audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('admin_user_id')->constrained('admin_users');
            $t->string('action', 80);
            $t->string('subject_type', 80);
            $t->unsignedBigInteger('subject_id')->nullable();
            $t->json('metadata')->nullable();
            $t->text('reason');
            $t->timestampTz('created_at')->useCurrent();
            $t->index(['subject_type', 'subject_id']);
        });
        Schema::create('user_restrictions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->string('restriction_type', 60);
            $t->timestampTz('starts_at');
            $t->timestampTz('ends_at')->nullable();
            $t->text('reason');
            $t->foreignId('admin_user_id')->constrained('admin_users');
            $t->timestampsTz();
            $t->index(['user_id', 'restriction_type', 'ends_at']);
        });
        foreach (['reports', 'event_reports'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->foreignId('assigned_admin_id')->nullable()->constrained('admin_users');
                $t->text('internal_notes')->nullable();
            });
        }
        DB::unprepared("CREATE OR REPLACE FUNCTION reject_immutable_admin_record() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION ''Immutable history cannot be changed''; END';");
        foreach (['admin_audit_logs', 'coin_transactions'] as $table) {
            DB::unprepared("CREATE TRIGGER immutable_history BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION reject_immutable_admin_record()");
        }
    }

    public function down(): void
    {
        foreach (['admin_audit_logs', 'coin_transactions'] as $table) {
            DB::unprepared("DROP TRIGGER IF EXISTS immutable_history ON {$table}");
        }
        DB::unprepared('DROP FUNCTION IF EXISTS reject_immutable_admin_record()');
        foreach (['reports', 'event_reports'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropConstrainedForeignId('assigned_admin_id');
                $t->dropColumn('internal_notes');
            });
        }
        Schema::dropIfExists('user_restrictions');
        Schema::dropIfExists('admin_audit_logs');
        Schema::table('admin_users', fn (Blueprint $t) => $t->dropColumn('role'));
    }
};
