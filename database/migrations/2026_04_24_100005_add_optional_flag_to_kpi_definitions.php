<?php

use App\Models\KpiDefinition;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kpi_definitions', function (Blueprint $table) {
            $table->boolean('optional')->default(false)->after('auto_sync');
        });

        // Mark all blockers fields as optional
        KpiDefinition::where('field_key', 'blockers')->update(['optional' => true]);
    }

    public function down(): void
    {
        Schema::table('kpi_definitions', function (Blueprint $table) {
            $table->dropColumn('optional');
        });
    }
};
