<?php

use App\Models\AgendaTemplate;
use App\Models\Meeting;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Create Nitha's recurring "Support Team Standup" (Mon–Fri, 12:00 PM) owned
     * by Nitha Sheri (#66, Team Lead-Operations) with her five Technical Support
     * reports as attendees: Deeksha (#25), Gousia (#26), Anjali (#48),
     * Reshma (#28), Nisha (#47).
     *
     * portal = Nitha's own role slug 'team_lead_operations' (meetings.portal is now
     * VARCHAR after 2026_06_03_000002, so the in-app meeting.access gate is the only
     * constraint — no ENUM landmine). attendees_only = true mirrors the AI Intern
     * Standup (2026_06_03_000200_add_attendees_only_to_meetings): the meeting is
     * hidden from the role-wide list and shown ONLY to its owner + attendees, i.e.
     * exactly Nitha + the five named people (no leakage to other roles).
     * Nitha sees it via owner_id; the five via the attendees JSON.
     *
     * Reuses the existing "Daily Stand-up Agenda (Ops Team)" agenda template so
     * the agenda auto-fills.
     */
    public function up(): void
    {
        $nitha = User::where('email', 'nitha@innovfix.in')->first();

        // Nitha owns the meeting — without her there is nothing to create.
        if (! $nitha) {
            return;
        }

        // Attendees are her five Technical Support reports (Nitha is the
        // owner/host, not listed as an attendee). Resolve by email so a missing
        // user is simply skipped rather than breaking the migration.
        $attendees = User::whereIn('email', [
            'deeksha@innovfix.in',
            'gousia@innovfix.in',
            'anjali@innovfix.in',
            'reshma@innovfix.in',
            'nisha@innovfix.in',
        ])->pluck('id')->all();

        // Reuse the existing Ops-team standup agenda; ?->id leaves it null if the
        // template is absent rather than failing the insert.
        $template = AgendaTemplate::where('name', 'Daily Stand-up Agenda (Ops Team)')->first();

        Meeting::updateOrCreate(
            ['meeting_key' => 'support-team-standup'],
            [
                'title' => 'Support Team Standup',
                'owner' => $nitha->name,
                'owner_id' => $nitha->id,
                'day_of_week' => 'Monday',        // first weekday; multi-day expands client-side
                'time' => '12:00 PM',
                'recurrence' => 'daily_weekdays', // Mon–Fri
                'portal' => 'team_lead_operations', // Nitha's role slug (portal is VARCHAR now)
                'attendees' => $attendees,        // [25, 26, 48, 28, 47]
                'attendees_only' => true,         // visible only to owner + attendees
                'agenda_template_id' => $template?->id,
                'created_by' => $nitha->id,
            ]
        );
    }

    public function down(): void
    {
        // Remove only the meeting; the agenda template is shared, so leave it.
        Meeting::where('meeting_key', 'support-team-standup')->delete();
    }
};
