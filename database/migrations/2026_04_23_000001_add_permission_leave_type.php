<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            $table->boolean('is_hourly')->default(false)->after('is_active');
        });

        Schema::table('leave_requests', function (Blueprint $table) {
            $table->decimal('hours', 4, 1)->nullable()->after('total_days');
        });

        DB::table('leave_types')->insert([
            'name' => 'Permission',
            'slug' => 'permission',
            'requires_approval' => true,
            'is_active' => true,
            'is_hourly' => true,
            'gender_restricted' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('leave_types')->where('slug', 'permission')->delete();

        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropColumn('hours');
        });

        Schema::table('leave_types', function (Blueprint $table) {
            $table->dropColumn('is_hourly');
        });
    }
};
