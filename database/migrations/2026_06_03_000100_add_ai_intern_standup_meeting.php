<?php

use App\Models\AgendaTemplate;
use App\Models\AgendaTemplateItem;
use App\Models\Meeting;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Create Fida's recurring "AI Intern Standup" (Mon–Fri, 12:00 PM) owned by
     * Fida (#41) with her three interns as attendees: Bhuvan (#59),
     * Bhoomika (#60), Soundarya (#62). Reuses the curated "AI Intern Standup"
     * agenda template (Yesterday's Progress / Today's Plan / Learning Progress /
     * Blockers) so the agenda auto-fills from saved notes.
     *
     * No portal ENUM change is needed: all four users are role
     * `gen_ai_developer`, which is already a valid meetings.portal value.
     */
    public function up(): void
    {
        $fida = User::where('email', 'fida@innovfix.in')->first();
        $bhuvan = User::where('email', 'bhuvan@innovfix.in')->first();
        $bhoomika = User::where('email', 'bhoomika@innovfix.in')->first();
        $soundarya = User::where('email', 'soundarya@innovfix.in')->first();

        // Fida owns the meeting — without her there is nothing to create.
        if (! $fida) {
            return;
        }

        // Reuse the existing "AI Intern Standup" template if present; otherwise
        // create it idempotently (mirrors StandupMeetingSeeder). In production the
        // template already exists with its items, so the seed block is a no-op.
        $template = AgendaTemplate::firstOrCreate(
            ['name' => 'AI Intern Standup'],
            ['created_by' => $fida->id]
        );

        if ($template->items()->count() === 0) {
            // A null point_question is a SECTION header; the following item with a
            // point_question becomes a discussion point under it (see
            // AgendaSectionController::seedFromTemplate).
            $items = [
                ['section_title' => "YESTERDAY'S PROGRESS", 'point_question' => null],
                ['section_title' => '', 'point_question' => 'What did you work on yesterday?'],
                ['section_title' => "TODAY'S PLAN", 'point_question' => null],
                ['section_title' => '', 'point_question' => 'What will you work on today?'],
                ['section_title' => 'LEARNING PROGRESS', 'point_question' => null],
                ['section_title' => '', 'point_question' => 'What new concepts or tools are you picking up?'],
                ['section_title' => 'BLOCKERS', 'point_question' => null],
                ['section_title' => '', 'point_question' => 'Any blockers or questions?'],
            ];

            $sortOrder = 1;
            foreach ($items as $item) {
                AgendaTemplateItem::create([
                    'template_id' => $template->id,
                    'section_title' => $item['section_title'],
                    'point_question' => $item['point_question'],
                    'sort_order' => $sortOrder++,
                ]);
            }
        }

        // Attendees are the three interns (Fida is the owner/host, not listed as
        // an attendee). Filter to whoever exists so a missing intern doesn't break
        // the migration.
        $attendees = collect([$bhuvan, $bhoomika, $soundarya])
            ->filter()
            ->map(fn (User $u) => $u->id)
            ->values()
            ->all();

        Meeting::updateOrCreate(
            ['meeting_key' => 'gen-ai-intern-standup'],
            [
                'title' => 'AI Intern Standup',
                'owner' => $fida->name,
                'owner_id' => $fida->id,
                'day_of_week' => 'Monday',        // first weekday; multi-day expands client-side
                'time' => '12:00 PM',
                'recurrence' => 'daily_weekdays', // Mon–Fri
                'portal' => 'gen_ai_developer',   // already a valid ENUM value
                'attendees' => $attendees,        // [59, 60, 62]
                'agenda_template_id' => $template->id,
                'created_by' => $fida->id,
            ]
        );
    }

    public function down(): void
    {
        // Remove only the meeting; the agenda template may be shared, so leave it.
        Meeting::where('meeting_key', 'gen-ai-intern-standup')->delete();
    }
};
