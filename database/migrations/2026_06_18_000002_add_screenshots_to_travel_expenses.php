<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('travel_expenses', function (Blueprint $table) {
            // screenshots shape: [{path, name, drive_file_id, drive_link}]
            $table->json('screenshots')->nullable()->after('note');
        });

        // Make screenshot_path nullable — new rows use screenshots JSON instead.
        DB::statement('ALTER TABLE travel_expenses MODIFY COLUMN screenshot_path VARCHAR(500) NULL');

        // Backfill: convert existing scalar columns into the screenshots JSON array.
        DB::table('travel_expenses')
            ->whereNotNull('screenshot_path')
            ->whereNull('screenshots')
            ->orderBy('id')
            ->each(function ($trip) {
                DB::table('travel_expenses')
                    ->where('id', $trip->id)
                    ->update([
                        'screenshots' => json_encode([[
                            'path'          => $trip->screenshot_path,
                            'name'          => $trip->screenshot_name ?? '',
                            'drive_file_id' => $trip->drive_file_id ?? null,
                            'drive_link'    => $trip->drive_link ?? null,
                        ]]),
                    ]);
            });
    }

    public function down(): void
    {
        Schema::table('travel_expenses', function (Blueprint $table) {
            $table->dropColumn('screenshots');
        });
        DB::statement('ALTER TABLE travel_expenses MODIFY COLUMN screenshot_path VARCHAR(500) NOT NULL DEFAULT ""');
    }
};
