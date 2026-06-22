<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (!DB::table('roles')->where('slug', 'technical_support')->exists()) {
            DB::table('roles')->insert([
                'name' => 'Technical Support',
                'slug' => 'technical_support',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('roles')->where('slug', 'technical_support')->delete();
    }
};
