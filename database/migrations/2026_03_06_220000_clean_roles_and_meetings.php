<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Update role 7 to Product Manager / product_manager (merge sudar_pm and thedal_pm)
        DB::table('roles')->where('id', 7)->update([
            'name' => 'Product Manager',
            'slug' => 'product_manager',
            'updated_at' => now(),
        ]);

        // 2. Reassign users with role_id 8 to role_id 7
        DB::table('users')->where('role_id', 8)->update(['role_id' => 7]);

        // 3. Delete role 8 if it exists
        DB::table('roles')->where('id', 8)->delete();

        // 4. Add 'marketing' to meetings.portal enum (MySQL)
        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE meetings MODIFY COLUMN portal ENUM('ops', 'ceo', 'coo', 'cmo', 'cfo', 'marketing') NOT NULL");
        }
    }

    public function down(): void
    {
        // Restore role 8
        DB::table('roles')->insert([
            'id' => 8,
            'name' => 'Product Manager (Thedal)',
            'slug' => 'thedal_pm',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Reassign Dhanush to role 8 (user 13)
        DB::table('users')->where('id', 13)->update(['role_id' => 8]);

        // Restore role 7
        DB::table('roles')->where('id', 7)->update([
            'name' => 'Product Manager (Sudar)',
            'slug' => 'sudar_pm',
            'updated_at' => now(),
        ]);

        // Revert meetings portal enum
        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE meetings MODIFY COLUMN portal ENUM('ops', 'ceo', 'coo', 'cmo', 'cfo') NOT NULL");
        }
    }
};
