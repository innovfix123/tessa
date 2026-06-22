<?php

namespace Database\Seeders;

use App\Models\AgendaTemplate;
use App\Models\AgendaTemplateItem;
use App\Models\Meeting;
use App\Models\User;
use Illuminate\Database\Seeder;

class StandupMeetingSeeder extends Seeder
{
    public function run(): void
    {
        $ayush = User::where('email', 'ayush@innovfix.in')->first();
        $shoyab = User::where('email', 'shoyab@innovfix.in')->first();

        if (!$ayush || !$shoyab) {
            return;
        }

        $template = AgendaTemplate::firstOrCreate(
            ['name' => 'Stand-up Meeting'],
            ['created_by' => $ayush->id]
        );

        if ($template->items()->count() === 0) {
            $items = [
                ['section_title' => 'Updates', 'point_question' => null],
                ['section_title' => '', 'point_question' => 'What did you accomplish since the last stand-up?'],
                ['section_title' => 'Plan', 'point_question' => null],
                ['section_title' => '', 'point_question' => 'What are you working on today?'],
                ['section_title' => 'Blockers', 'point_question' => null],
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

        Meeting::updateOrCreate(
            ['meeting_key' => 'cfo-stand-up-meeting'],
            [
                'title' => 'Stand-up Meeting',
                'owner' => $ayush->name,
                'owner_id' => $ayush->id,
                'day_of_week' => 'Monday',
                'time' => '11:00 AM',
                'recurrence' => 'daily_weekdays',
                'portal' => 'cfo',
                'attendees' => [$shoyab->id],
                'agenda_template_id' => $template->id,
                'created_by' => $ayush->id,
            ]
        );
    }
}
