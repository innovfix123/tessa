<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('weekly_timesheets', function (Blueprint $table) {
            // Which weekend day(s) the overtime covered — independently tickable
            // in the Overtime section (employee can pick both, one, or neither).
            $table->boolean('overtime_saturday')->default(false)->after('overtime_summary');
            $table->boolean('overtime_sunday')->default(false)->after('overtime_saturday');
        });
    }

    public function down(): void
    {
        Schema::table('weekly_timesheets', function (Blueprint $table) {
            $table->dropColumn(['overtime_saturday', 'overtime_sunday']);
        });
    }
};
