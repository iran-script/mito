<?php

use App\Domain\Users\MitoId;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('public_mito_id', 10)->nullable()->after('id');
        });
        DB::table('users')->select('id')->orderBy('id')->chunkById(500, function ($users): void {
            foreach ($users as $user) {
                do {
                    $id = MitoId::generate();
                } while (DB::table('users')->where('public_mito_id', $id)->exists());
                DB::table('users')->where('id', $user->id)->update(['public_mito_id' => $id]);
            }
        });
        DB::statement('ALTER TABLE users ALTER COLUMN public_mito_id SET NOT NULL');
        Schema::table('users', function (Blueprint $table) {
            $table->unique('public_mito_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['public_mito_id']);
            $table->dropColumn('public_mito_id');
        });
    }
};
