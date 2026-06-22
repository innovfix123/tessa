<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Interview agenda / syllabus for a round (Feature 9C). When drafting the
 * technical-interview invite, HR includes an "INTERVIEW AGENDA" + "TOPICS TO
 * PREPARE" block (editable before sending). Stored here for the record and
 * surfaced read-only to HR. Mirrors the 9A `feedback` column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidate_interviews', function (Blueprint $table) {
            $table->text('agenda')->nullable()->after('feedback');
        });
    }

    public function down(): void
    {
        Schema::table('candidate_interviews', function (Blueprint $table) {
            $table->dropColumn('agenda');
        });
    }
};
