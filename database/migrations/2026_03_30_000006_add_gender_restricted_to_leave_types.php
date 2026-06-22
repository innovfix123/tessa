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
            $table->string('gender_restricted', 10)->nullable()->after('is_active');
        });

        // Menstrual leave is only for female employees
        DB::table('leave_types')->where('slug', 'menstrual')->update([
            'gender_restricted' => 'female',
        ]);
    }

    public function down(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            $table->dropColumn('gender_restricted');
        });
    }
};
