<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kpi_definitions', function (Blueprint $table) {
            $table->json('choices')->nullable()->after('upload_max_mb');
        });

        Schema::table('daily_reports', function (Blueprint $table) {
            $table->string('choice_value', 64)->nullable()->after('value');
        });
    }

    public function down(): void
    {
        Schema::table('daily_reports', function (Blueprint $table) {
            $table->dropColumn('choice_value');
        });

        Schema::table('kpi_definitions', function (Blueprint $table) {
            $table->dropColumn('choices');
        });
    }
};
