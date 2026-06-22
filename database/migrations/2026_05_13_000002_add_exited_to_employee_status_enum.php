<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL doesn't have a clean "add enum value" syntax, so re-declare the
        // full enum including the new 'exited' option. Kept all existing values
        // so historical rows (terminated/resigned/absconding) still validate.
        DB::statement(
            "ALTER TABLE users MODIFY COLUMN employee_status ".
            "ENUM('active','probation','notice_period','resigned','terminated','absconding','intern','exited') ".
            "NOT NULL DEFAULT 'active'"
        );
    }

    public function down(): void
    {
        // Revert by demoting any 'exited' rows back to 'resigned' so the
        // narrower enum can be re-applied without data loss.
        DB::table('users')->where('employee_status', 'exited')->update(['employee_status' => 'resigned']);

        DB::statement(
            "ALTER TABLE users MODIFY COLUMN employee_status ".
            "ENUM('active','probation','notice_period','resigned','terminated','absconding','intern') ".
            "NOT NULL DEFAULT 'active'"
        );
    }
};
