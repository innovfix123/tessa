<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kpi_definitions', function (Blueprint $table) {
            $table->boolean('auto_sync')->default(false)->after('aggregation');
        });

        // Mark Sneha's Hima conversion KPI fields as auto-synced
        DB::table('kpi_definitions')
            ->where('user_id', 5)
            ->whereIn('field_key', [
                'tamil_new_users_paying_conversion_pct',
                'tamil_new_users_avg_paying_amount',
                'telugu_new_users_paying_conversion_pct',
                'telugu_new_users_avg_paying_amount',
                'kannada_new_users_paying_conversion_pct',
                'kannada_new_users_avg_paying_amount',
                'malayalam_new_users_paying_conversion_pct',
                'malayalam_new_users_avg_paying_amount',
            ])
            ->update(['auto_sync' => true]);
    }

    public function down(): void
    {
        Schema::table('kpi_definitions', function (Blueprint $table) {
            $table->dropColumn('auto_sync');
        });
    }
};
