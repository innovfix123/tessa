<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('manager_work_reviews', function (Blueprint $table) {
            $table->unsignedTinyInteger('rating')->default(0)->after('week_key');
        });

        // Backfill: average of the 3 category ratings
        DB::table('manager_work_reviews')
            ->whereNotNull('rating_discipline')
            ->update([
                'rating' => DB::raw('ROUND((COALESCE(rating_discipline,0) + COALESCE(rating_deliverables,0) + COALESCE(rating_quality,0)) / 3)'),
            ]);

        Schema::table('manager_work_reviews', function (Blueprint $table) {
            $table->dropColumn(['rating_discipline', 'rating_deliverables', 'rating_quality']);
        });
    }

    public function down(): void
    {
        Schema::table('manager_work_reviews', function (Blueprint $table) {
            $table->unsignedTinyInteger('rating_discipline')->nullable()->after('week_key');
            $table->unsignedTinyInteger('rating_deliverables')->nullable()->after('rating_discipline');
            $table->unsignedTinyInteger('rating_quality')->nullable()->after('rating_deliverables');
        });

        DB::table('manager_work_reviews')->update([
            'rating_discipline'   => DB::raw('rating'),
            'rating_deliverables' => DB::raw('rating'),
            'rating_quality'      => DB::raw('rating'),
        ]);

        Schema::table('manager_work_reviews', function (Blueprint $table) {
            $table->dropColumn('rating');
        });
    }
};
