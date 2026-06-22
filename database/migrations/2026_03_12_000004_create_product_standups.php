<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\Role;
use App\Models\Meeting;
use App\Models\AgendaTemplate;
use App\Models\AgendaTemplateItem;

return new class extends Migration
{
    public function up(): void
    {
        $yuvanesh = User::where('email', 'yuvanesh@innovfix.in')->first();
        if (!$yuvanesh) {
            return;
        }

        $techLeadRole = Role::where('slug', 'tech_lead')->first();
        if (!$techLeadRole) {
            return;
        }

        $passwordHash = password_hash('12345678', PASSWORD_BCRYPT);

        // 1. Create Only Care project
        $onlyCareProject = DB::table('projects')->where('name', 'Only Care')->first();
        if (!$onlyCareProject) {
            $onlyCareProjectId = DB::table('projects')->insertGetId([
                'name' => 'Only Care',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $onlyCareProjectId = $onlyCareProject->id;
        }

        // 2. Create users Perumal and Maari
        $perumal = User::updateOrCreate(
            ['email' => 'perumal@innovfix.in'],
            [
                'name' => 'Perumal',
                'password_hash' => $passwordHash,
                'role_id' => $techLeadRole->id,
                'reporting_manager_id' => $yuvanesh->id,
                'is_active' => true,
            ]
        );

        $maari = User::updateOrCreate(
            ['email' => 'maari@innovfix.in'],
            [
                'name' => 'Maari',
                'password_hash' => $passwordHash,
                'role_id' => $techLeadRole->id,
                'reporting_manager_id' => $yuvanesh->id,
                'is_active' => true,
            ]
        );

        // Project assignments: Perumal -> Thedal (id=3), Maari -> Only Care
        $perumalExists = DB::table('project_assignments')
            ->where('user_id', $perumal->id)
            ->where('project_id', 3)
            ->exists();
        if (!$perumalExists) {
            DB::table('project_assignments')->insert([
                'user_id' => $perumal->id,
                'project_id' => 3,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $maariExists = DB::table('project_assignments')
            ->where('user_id', $maari->id)
            ->where('project_id', $onlyCareProjectId)
            ->exists();
        if (!$maariExists) {
            DB::table('project_assignments')->insert([
                'user_id' => $maari->id,
                'project_id' => $onlyCareProjectId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 3. Create agenda templates
        $standupItems = [
            ['section_title' => "YESTERDAY'S WORK", 'point_question' => null],
            ['section_title' => '', 'point_question' => "What tasks were completed yesterday?"],
            ['section_title' => "TODAY'S PLAN", 'point_question' => null],
            ['section_title' => '', 'point_question' => "What are you working on today?"],
            ['section_title' => 'PRODUCT UPDATES', 'point_question' => null],
            ['section_title' => '', 'point_question' => 'Any product issues or user feedback?'],
            ['section_title' => '', 'point_question' => 'Feature progress update'],
            ['section_title' => 'BLOCKERS', 'point_question' => null],
            ['section_title' => '', 'point_question' => 'Any blockers or dependencies?'],
        ];

        $onlyCareTemplate = AgendaTemplate::firstOrCreate(
            ['name' => 'Only Care Standup'],
            ['created_by' => $yuvanesh->id]
        );
        if ($onlyCareTemplate->items()->count() === 0) {
            $sortOrder = 1;
            foreach ($standupItems as $item) {
                AgendaTemplateItem::create([
                    'template_id' => $onlyCareTemplate->id,
                    'section_title' => $item['section_title'],
                    'point_question' => $item['point_question'],
                    'sort_order' => $sortOrder++,
                ]);
            }
        }

        $sudarThedalTemplate = AgendaTemplate::firstOrCreate(
            ['name' => 'Sudar + Thedal Standup'],
            ['created_by' => $yuvanesh->id]
        );
        if ($sudarThedalTemplate->items()->count() === 0) {
            $sortOrder = 1;
            foreach ($standupItems as $item) {
                AgendaTemplateItem::create([
                    'template_id' => $sudarThedalTemplate->id,
                    'section_title' => $item['section_title'],
                    'point_question' => $item['point_question'],
                    'sort_order' => $sortOrder++,
                ]);
            }
        }

        // 4. Create meetings
        $tamil = User::where('email', 'tamil@innovfix.in')->first();

        Meeting::updateOrCreate(
            ['meeting_key' => 'tech-lead-onlycare-standup'],
            [
                'title' => 'Only Care Standup',
                'owner' => $yuvanesh->name,
                'owner_id' => $yuvanesh->id,
                'day_of_week' => 'Monday',
                'time' => '11:00 AM',
                'recurrence' => 'daily_weekdays',
                'portal' => 'tech_lead',
                'attendees' => [$maari->id],
                'agenda_template_id' => $onlyCareTemplate->id,
                'created_by' => $yuvanesh->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        Meeting::updateOrCreate(
            ['meeting_key' => 'tech-lead-sudar-thedal-standup'],
            [
                'title' => 'Sudar + Thedal Standup',
                'owner' => $yuvanesh->name,
                'owner_id' => $yuvanesh->id,
                'day_of_week' => 'Monday',
                'time' => '12:00 PM',
                'recurrence' => 'daily_weekdays',
                'portal' => 'tech_lead',
                'attendees' => $tamil ? [$tamil->id, $perumal->id] : [$perumal->id],
                'agenda_template_id' => $sudarThedalTemplate->id,
                'created_by' => $yuvanesh->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        Meeting::where('meeting_key', 'tech-lead-onlycare-standup')->delete();
        Meeting::where('meeting_key', 'tech-lead-sudar-thedal-standup')->delete();

        $onlyCareTemplate = AgendaTemplate::where('name', 'Only Care Standup')->first();
        if ($onlyCareTemplate) {
            AgendaTemplateItem::where('template_id', $onlyCareTemplate->id)->delete();
            $onlyCareTemplate->delete();
        }

        $sudarThedalTemplate = AgendaTemplate::where('name', 'Sudar + Thedal Standup')->first();
        if ($sudarThedalTemplate) {
            AgendaTemplateItem::where('template_id', $sudarThedalTemplate->id)->delete();
            $sudarThedalTemplate->delete();
        }

        $perumal = User::where('email', 'perumal@innovfix.in')->first();
        $maari = User::where('email', 'maari@innovfix.in')->first();

        if ($perumal) {
            DB::table('project_assignments')
                ->where('user_id', $perumal->id)
                ->where('project_id', 3)
                ->delete();
        }
        if ($maari) {
            $onlyCareProject = DB::table('projects')->where('name', 'Only Care')->first();
            if ($onlyCareProject) {
                DB::table('project_assignments')
                    ->where('user_id', $maari->id)
                    ->where('project_id', $onlyCareProject->id)
                    ->delete();
            }
        }

        User::whereIn('email', ['perumal@innovfix.in', 'maari@innovfix.in'])->delete();

        DB::table('projects')->where('name', 'Only Care')->delete();
    }
};
