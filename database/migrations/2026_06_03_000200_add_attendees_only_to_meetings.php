<?php

use App\Models\Meeting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-meeting visibility flag. Default false = existing behaviour (a meeting is
     * visible to everyone whose role matches `portal`). When true, the meeting is
     * hidden from the role-wide list and shown only to its owner and attendees.
     *
     * Flags Fida's "AI Intern Standup" (gen-ai-intern-standup) so it's visible only
     * to Fida (#41) + the three interns (#59, #60, #62) — not to every gen_ai_developer.
     *
     * Timestamped after the standup's creation migration (…000100) so it works on both
     * the live DB (adds the column, then flags the existing row) and a fresh rebuild
     * (meeting created first, then column added + flagged).
     */
    public function up(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->boolean('attendees_only')->default(false)->after('attendees');
        });

        Meeting::where('meeting_key', 'gen-ai-intern-standup')->update(['attendees_only' => true]);
    }

    public function down(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->dropColumn('attendees_only');
        });
    }
};
