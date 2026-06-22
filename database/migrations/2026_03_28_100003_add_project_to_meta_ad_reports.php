<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meta_ad_reports', function (Blueprint $table) {
            $table->string('project', 50)->default('hima')->after('id');
            $table->index('project');
        });

        // Update existing row hashes to include project
        $rows = DB::table('meta_ad_reports')->get();
        foreach ($rows as $row) {
            $hash = hash('sha256', implode('|', [
                $row->project,
                $row->campaign_name,
                $row->ad_set_name,
                $row->ad_name,
                $row->reporting_starts,
                $row->reporting_ends,
            ]));
            DB::table('meta_ad_reports')->where('id', $row->id)->update(['row_hash' => $hash]);
        }
    }

    public function down(): void
    {
        Schema::table('meta_ad_reports', function (Blueprint $table) {
            $table->dropIndex(['project']);
            $table->dropColumn('project');
        });
    }
};
