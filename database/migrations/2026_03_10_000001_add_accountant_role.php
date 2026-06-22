<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (!DB::table('roles')->where('slug', 'accountant')->exists()) {
            DB::table('roles')->insert([
                'name' => 'Accountant',
                'slug' => 'accountant',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('roles')->where('slug', 'accountant')->delete();
    }
};
