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
            $table->unsignedTinyInteger('rating_deliverables')->nullable()->after('rating');
            $table->unsignedTinyInteger('rating_quality')->nullable()->after('rating_deliverables');
        });

        // Backfill: copy old single rating into both columns
        DB::table('manager_work_reviews')
            ->whereNotNull('rating')
            ->update([
                'rating_deliverables' => DB::raw('rating'),
                'rating_quality'      => DB::raw('rating'),
            ]);

        Schema::table('manager_work_reviews', function (Blueprint $table) {
            $table->dropColumn('rating');
        });
    }

    public function down(): void
    {
        Schema::table('manager_work_reviews', function (Blueprint $table) {
            $table->unsignedTinyInteger('rating')->default(0)->after('week_key');
        });

        DB::table('manager_work_reviews')->update([
            'rating' => DB::raw('ROUND((COALESCE(rating_deliverables,0) + COALESCE(rating_quality,0)) / 2)'),
        ]);

        Schema::table('manager_work_reviews', function (Blueprint $table) {
            $table->dropColumn(['rating_deliverables', 'rating_quality']);
        });
    }
};
