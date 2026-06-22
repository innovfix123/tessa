<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Panel feedback for an interview round (Feature 9A). The technical-round panel
 * records strengths/weaknesses/overall assessment (required, min 50 chars,
 * enforced in the controller) alongside their Accept/Reject decision. Stored
 * here and surfaced read-only to HR.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidate_interviews', function (Blueprint $table) {
            $table->text('feedback')->nullable()->after('outcome');
        });
    }

    public function down(): void
    {
        Schema::table('candidate_interviews', function (Blueprint $table) {
            $table->dropColumn('feedback');
        });
    }
};
