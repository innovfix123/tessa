<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('kpi_definitions', function (Blueprint $table) {
            $table->string('project_name', 100)->nullable()->after('person_role');
        });

        // Backfill existing rows based on person_id
        DB::table('kpi_definitions')
            ->where('person_id', 'tamil-sudar')
            ->update(['project_name' => 'Sudar']);

        DB::table('kpi_definitions')
            ->where('person_id', 'anirudh-marketing')
            ->update(['project_name' => 'Marketing']);

        DB::table('kpi_definitions')
            ->where('person_id', 'dhanush-thedal')
            ->update(['project_name' => 'Thedal']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('kpi_definitions', function (Blueprint $table) {
            $table->dropColumn('project_name');
        });
    }
};
