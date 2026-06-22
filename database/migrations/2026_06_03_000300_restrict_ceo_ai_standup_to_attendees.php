<?php

use App\Models\Meeting;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Flag "AI Standup With JP" (ceo-ai-standup) as attendees-only so it's visible only
     * to its owner (JP #1) + attendees (Fida #41, Sneha #42, Ranjini #27) — not to every
     * gen_ai_developer. The interns (#59/#60/#62) attend only the AI Intern Standup, so
     * they should no longer see this one. Reuses the meetings.attendees_only mechanism.
     *
     * Data-only change (column added in …000200); runs after both meetings exist, so it
     * is correct on the live DB and on a fresh rebuild.
     */
    public function up(): void
    {
        Meeting::where('meeting_key', 'ceo-ai-standup')->update(['attendees_only' => true]);
    }

    public function down(): void
    {
        Meeting::where('meeting_key', 'ceo-ai-standup')->update(['attendees_only' => false]);
    }
};
