<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kpi_definitions', function (Blueprint $table) {
            $table->string('input_type', 20)->default('text')->after('auto_sync');
            $table->string('upload_accept')->nullable()->after('input_type');
            $table->unsignedInteger('upload_max_mb')->nullable()->after('upload_accept');
        });

        // Anaz (user 18) - videos_delivered
        DB::table('kpi_definitions')
            ->where('user_id', 18)
            ->where('field_key', 'videos_delivered')
            ->update([
                'input_type' => 'upload',
                'upload_accept' => 'mp4,mov,avi,mkv,webm',
                'upload_max_mb' => 100,
            ]);

        // Sooraj (user 19) - designs_delivered
        DB::table('kpi_definitions')
            ->where('user_id', 19)
            ->where('field_key', 'designs_delivered')
            ->update([
                'input_type' => 'upload',
                'upload_accept' => 'png,jpg,jpeg,pdf,psd,ai,svg',
                'upload_max_mb' => 20,
            ]);
    }

    public function down(): void
    {
        Schema::table('kpi_definitions', function (Blueprint $table) {
            $table->dropColumn(['input_type', 'upload_accept', 'upload_max_mb']);
        });
    }
};
