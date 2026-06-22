<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Video handoff naming: the per-language sequence number assigned to a raw
 * video the first time Anaz reworks/approves it. Stored on the raw (the
 * logical video) so a delivered filename never shifts as new videos are
 * approved. Null until approved — naming happens only on approval, never at
 * the creator's initial upload. See App\Services\VideoHandoffNaming.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('creative_uploads', function (Blueprint $table) {
            $table->unsignedInteger('handoff_seq')->nullable()->after('content');
        });
    }

    public function down(): void
    {
        Schema::table('creative_uploads', function (Blueprint $table) {
            $table->dropColumn('handoff_seq');
        });
    }
};
