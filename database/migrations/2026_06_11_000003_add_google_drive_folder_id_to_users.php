<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Per-person Google Drive folder id. GoogleDriveService::ensureFolder() creates a
 * subfolder named after the employee under the master HR folder on first document
 * upload; we persist its id here so the HR Employee Documents view can embed that
 * exact folder (and so subsequent uploads skip the Drive search).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'google_drive_folder_id')) {
                $table->string('google_drive_folder_id', 64)->nullable()->after('insurance_policy_path');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'google_drive_folder_id')) {
                $table->dropColumn('google_drive_folder_id');
            }
        });
    }
};
