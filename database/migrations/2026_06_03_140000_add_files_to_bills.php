<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bills', function (Blueprint $table) {
            // A request can carry multiple attachments — e.g. the invoice AND a
            // payment QR, or several invoice pages. `files` holds them all as
            // [{path, name, size}, ...]. The existing file_path/file_name/
            // file_size columns stay populated with the FIRST file so older code
            // paths (records export, pre-migration rows) keep working.
            $table->json('files')->nullable()->after('file_size');
        });
    }

    public function down(): void
    {
        Schema::table('bills', function (Blueprint $table) {
            $table->dropColumn('files');
        });
    }
};
