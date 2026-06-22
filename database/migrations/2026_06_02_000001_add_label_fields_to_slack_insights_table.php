<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add label/routing fields so the dashboard can show a precise meeting label
     * per viewer and so unified (audience='meeting') rows know the full huddle
     * roster:
     *
     *  - meeting_kind         : 'one_on_one' | 'channel' | 'group' | 'scheduled'.
     *                           Drives the card label ("1:1 with X", "ai-intern
     *                           huddle", the scheduled meeting name, …).
     *  - meeting_attendee_ids : JSON array of the FULL set of Tessa user IDs who
     *                           were in the huddle. (audience_user_ids may be a
     *                           narrower set — e.g. [doer, manager] — so we keep
     *                           the roster separately to resolve the "other"
     *                           person in a 1:1 label.)
     */
    public function up(): void
    {
        Schema::table('slack_insights', function (Blueprint $table) {
            $table->string('meeting_kind', 20)->nullable()->after('meeting_date');
            $table->json('meeting_attendee_ids')->nullable()->after('audience_user_ids');
        });
    }

    public function down(): void
    {
        Schema::table('slack_insights', function (Blueprint $table) {
            $table->dropColumn(['meeting_kind', 'meeting_attendee_ids']);
        });
    }
};
