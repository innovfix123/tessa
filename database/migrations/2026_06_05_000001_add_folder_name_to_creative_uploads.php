<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('creative_uploads', function (Blueprint $table) {
            // Display-only label of the folder a creator uploaded as a batch, so
            // the upload panel can show it as one named, expandable folder card.
            // NULL = loose single-file upload (renders as a flat card, as before).
            // Never used in a storage path — sanitized to a basename label only.
            $table->string('folder_name', 255)->nullable()->after('content');
        });
    }

    public function down(): void
    {
        Schema::table('creative_uploads', function (Blueprint $table) {
            $table->dropColumn('folder_name');
        });
    }
};
