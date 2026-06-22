<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\AgendaTemplate;
use App\Models\AgendaTemplateItem;
use App\Models\Meeting;

return new class extends Migration
{
    public function up(): void
    {
        // Get Yuvanesh user for created_by
        $yuvanesh = User::where('email', 'yuvanesh@innovfix.in')->first();
        if (!$yuvanesh) {
            return; // Yuvanesh should exist
        }

        // Create or get the template
        $template = AgendaTemplate::firstOrCreate(
            ['name' => 'Hima Tech Standup'],
            ['created_by' => $yuvanesh->id]
        );

        // Only create items if template is new (no items exist)
        if ($template->items()->count() === 0) {
            $items = [
                ['section_title' => "YESTERDAY'S WORK", 'point_question' => null],
                ['section_title' => '', 'point_question' => "What tasks were completed yesterday?"],
                ['section_title' => "TODAY'S PLAN", 'point_question' => null],
                ['section_title' => '', 'point_question' => "What are you working on today?"],
                ['section_title' => 'BUG FIXES & ISSUES', 'point_question' => null],
                ['section_title' => '', 'point_question' => 'Any bugs fixed or reported yesterday?'],
                ['section_title' => '', 'point_question' => 'Any open bugs that need attention?'],
                ['section_title' => 'FEATURE PROGRESS', 'point_question' => null],
                ['section_title' => '', 'point_question' => 'What is the current feature being worked on?'],
                ['section_title' => '', 'point_question' => 'Is it on track for the expected timeline?'],
                ['section_title' => 'RELEASE STATUS', 'point_question' => null],
                ['section_title' => '', 'point_question' => 'Any release planned or pushed today?'],
                ['section_title' => '', 'point_question' => 'Any post-release issues from the last build?'],
                ['section_title' => 'BLOCKERS', 'point_question' => null],
                ['section_title' => '', 'point_question' => 'Any blockers or issues?'],
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

        // Update the Hima Standup meeting to use this template
        $meeting = Meeting::where('meeting_key', 'tech-lead-hima-standup')->first();
        if ($meeting) {
            $meeting->update([
                'agenda_template_id' => $template->id,
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Revert meeting to use the generic Stand-up Meeting template (ID: 18)
        $meeting = Meeting::where('meeting_key', 'tech-lead-hima-standup')->first();
        if ($meeting) {
            $genericTemplate = AgendaTemplate::where('name', 'Stand-up Meeting')->first();
            if ($genericTemplate) {
                $meeting->update([
                    'agenda_template_id' => $genericTemplate->id,
                    'updated_at' => now(),
                ]);
            }
        }

        // Delete the template and its items
        $template = AgendaTemplate::where('name', 'Hima Tech Standup')->first();
        if ($template) {
            AgendaTemplateItem::where('template_id', $template->id)->delete();
            $template->delete();
        }
    }
};
