<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aspect ratio of a reworked video. Anaz delivers each raw video in three
 * crops — 1:1 (square), 9:16 (portrait), 16:9 (landscape) — uploaded through
 * three separate, ratio-enforced boxes. The detected ratio is stored here
 * (verified server-side via ffprobe in App\Services\VideoAspectRatio).
 *
 * Nullable: rows created before the per-ratio boxes shipped have no detected
 * ratio and are treated as "legacy" (grandfathered as complete, shown in an
 * Unsorted section). New uploads always carry one of '1:1' | '9:16' | '16:9'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_handoffs', function (Blueprint $table) {
            $table->string('ratio', 8)->nullable()->after('updated_file_type');
        });
    }

    public function down(): void
    {
        Schema::table('video_handoffs', function (Blueprint $table) {
            $table->dropColumn('ratio');
        });
    }
};
