<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'sign_sound')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('sign_sound');
            });
        }

        // Clear the now-orphaned record for the original add migration so a
        // future migrate:rollback doesn't trip on the missing file.
        DB::table('migrations')
            ->where('migration', '2026_05_24_000011_add_sign_sound_to_users')
            ->delete();
    }

    public function down(): void
    {
        // No-op: the column drop is intentional. Restoring it would require
        // re-adding the original migration file.
    }
};
