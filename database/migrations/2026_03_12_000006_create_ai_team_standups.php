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
        $jp = User::where('email', 'jp@innovfix.in')->first();
        if (!$jp) {
            return;
        }

        $passwordHash = password_hash('12345678', PASSWORD_BCRYPT);

        // 1. Create gen_ai_developer role
        $gadRole = DB::table('roles')->where('slug', 'gen_ai_developer')->first();
        if (!$gadRole) {
            $gadRoleId = DB::table('roles')->insertGetId([
                'name' => 'Gen AI Developer',
                'slug' => 'gen_ai_developer',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $gadRoleId = $gadRole->id;
        }

        // 2. Add gen_ai_developer to meetings portal enum
        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE meetings MODIFY COLUMN portal ENUM('ops', 'ceo', 'coo', 'cmo', 'cfo', 'marketing', 'product_manager', 'tech_lead', 'full_stack_developer', 'content_lead', 'gen_ai_developer') NOT NULL");
        }

        // 3. Add permissions for gen_ai_developer
        $gadPermissions = [
            'meeting.access',
            'feature.meetings',
            'feature.dashboard',
            'feature.calendar',
            'feature.org',
            'feature.templates',
            'template.manage',
            'org.view',
        ];
        foreach ($gadPermissions as $permission) {
            $exists = DB::table('permissions')
                ->where('role_id', $gadRoleId)
                ->where('permission', $permission)
                ->exists();
            if (!$exists) {
                DB::table('permissions')->insert([
                    'role_id' => $gadRoleId,
                    'permission' => $permission,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // 4. Create Fida user
        $fida = User::updateOrCreate(
            ['email' => 'fida@innovfix.in'],
            [
                'name' => 'Fida',
                'password_hash' => $passwordHash,
                'role_id' => $gadRoleId,
                'reporting_manager_id' => $jp->id,
                'is_active' => true,
            ]
        );

        $fidaProjectExists = DB::table('project_assignments')
            ->where('user_id', $fida->id)
            ->where('project_id', 1)
            ->exists();
        if (!$fidaProjectExists) {
            DB::table('project_assignments')->insert([
                'user_id' => $fida->id,
                'project_id' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 5. Create Sneha Prathap user
        $snehaPrathap = User::updateOrCreate(
            ['email' => 'snehaintern@innovfix.in'],
            [
                'name' => 'Sneha Prathap',
                'password_hash' => $passwordHash,
                'role_id' => $gadRoleId,
                'reporting_manager_id' => $jp->id,
                'is_active' => true,
            ]
        );

        $snehaPrathapProjectExists = DB::table('project_assignments')
            ->where('user_id', $snehaPrathap->id)
            ->where('project_id', 1)
            ->exists();
        if (!$snehaPrathapProjectExists) {
            DB::table('project_assignments')->insert([
                'user_id' => $snehaPrathap->id,
                'project_id' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 6. Create Sanika user (reports to Sneha Prathap)
        $sanika = User::updateOrCreate(
            ['email' => 'sanika@innovfix.in'],
            [
                'name' => 'Sanika',
                'password_hash' => $passwordHash,
                'role_id' => $gadRoleId,
                'reporting_manager_id' => $snehaPrathap->id,
                'is_active' => true,
            ]
        );

        $sanikaProjectExists = DB::table('project_assignments')
            ->where('user_id', $sanika->id)
            ->where('project_id', 1)
            ->exists();
        if (!$sanikaProjectExists) {
            DB::table('project_assignments')->insert([
                'user_id' => $sanika->id,
                'project_id' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 7. Create AI Standup agenda template
        $aiStandupItems = [
            ['section_title' => "YESTERDAY'S PROGRESS", 'point_question' => null],
            ['section_title' => '', 'point_question' => 'What did you work on yesterday?'],
            ['section_title' => "TODAY'S PLAN", 'point_question' => null],
            ['section_title' => '', 'point_question' => 'What are you working on today?'],
            ['section_title' => 'AI MODEL / RESEARCH UPDATES', 'point_question' => null],
            ['section_title' => '', 'point_question' => 'Any model training results, new findings, or experiment outcomes?'],
            ['section_title' => 'BLOCKERS', 'point_question' => null],
            ['section_title' => '', 'point_question' => 'Any blockers or dependencies?'],
        ];

        $aiStandupTemplate = AgendaTemplate::firstOrCreate(
            ['name' => 'AI Standup'],
            ['created_by' => $jp->id]
        );
        if ($aiStandupTemplate->items()->count() === 0) {
            $sortOrder = 1;
            foreach ($aiStandupItems as $item) {
                AgendaTemplateItem::create([
                    'template_id' => $aiStandupTemplate->id,
                    'section_title' => $item['section_title'],
                    'point_question' => $item['point_question'],
                    'sort_order' => $sortOrder++,
                ]);
            }
        }

        // 8. Create AI Intern Standup agenda template
        $aiInternItems = [
            ['section_title' => "YESTERDAY'S PROGRESS", 'point_question' => null],
            ['section_title' => '', 'point_question' => 'What did you work on yesterday?'],
            ['section_title' => "TODAY'S PLAN", 'point_question' => null],
            ['section_title' => '', 'point_question' => 'What will you work on today?'],
            ['section_title' => 'LEARNING PROGRESS', 'point_question' => null],
            ['section_title' => '', 'point_question' => 'What new concepts or tools are you picking up?'],
            ['section_title' => 'BLOCKERS', 'point_question' => null],
            ['section_title' => '', 'point_question' => 'Any blockers or questions?'],
        ];

        $aiInternTemplate = AgendaTemplate::firstOrCreate(
            ['name' => 'AI Intern Standup'],
            ['created_by' => $snehaPrathap->id]
        );
        if ($aiInternTemplate->items()->count() === 0) {
            $sortOrder = 1;
            foreach ($aiInternItems as $item) {
                AgendaTemplateItem::create([
                    'template_id' => $aiInternTemplate->id,
                    'section_title' => $item['section_title'],
                    'point_question' => $item['point_question'],
                    'sort_order' => $sortOrder++,
                ]);
            }
        }

        // 9. Create AI Standup meeting (JP + Fida + Sneha Prathap at 10:30 AM)
        Meeting::updateOrCreate(
            ['meeting_key' => 'ceo-ai-standup'],
            [
                'title' => 'AI Standup',
                'owner' => $jp->name,
                'owner_id' => $jp->id,
                'day_of_week' => 'Monday',
                'time' => '10:30 AM',
                'recurrence' => 'daily_weekdays',
                'portal' => 'gen_ai_developer',
                'attendees' => [$fida->id, $snehaPrathap->id],
                'agenda_template_id' => $aiStandupTemplate->id,
                'created_by' => $jp->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        // 10. Create AI Intern Standup meeting (Sneha Prathap + Sanika at 11:00 AM)
        Meeting::updateOrCreate(
            ['meeting_key' => 'gen-ai-intern-standup'],
            [
                'title' => 'AI Intern Standup',
                'owner' => $snehaPrathap->name,
                'owner_id' => $snehaPrathap->id,
                'day_of_week' => 'Monday',
                'time' => '11:00 AM',
                'recurrence' => 'daily_weekdays',
                'portal' => 'gen_ai_developer',
                'attendees' => [$sanika->id],
                'agenda_template_id' => $aiInternTemplate->id,
                'created_by' => $snehaPrathap->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        Meeting::where('meeting_key', 'ceo-ai-standup')->delete();
        Meeting::where('meeting_key', 'gen-ai-intern-standup')->delete();

        $aiStandupTemplate = AgendaTemplate::where('name', 'AI Standup')->first();
        if ($aiStandupTemplate) {
            AgendaTemplateItem::where('template_id', $aiStandupTemplate->id)->delete();
            $aiStandupTemplate->delete();
        }

        $aiInternTemplate = AgendaTemplate::where('name', 'AI Intern Standup')->first();
        if ($aiInternTemplate) {
            AgendaTemplateItem::where('template_id', $aiInternTemplate->id)->delete();
            $aiInternTemplate->delete();
        }

        $fida = User::where('email', 'fida@innovfix.in')->first();
        $snehaPrathap = User::where('email', 'snehaintern@innovfix.in')->first();
        $sanika = User::where('email', 'sanika@innovfix.in')->first();

        if ($sanika) {
            DB::table('project_assignments')->where('user_id', $sanika->id)->delete();
            $sanika->delete();
        }
        if ($snehaPrathap) {
            DB::table('project_assignments')->where('user_id', $snehaPrathap->id)->delete();
            $snehaPrathap->delete();
        }
        if ($fida) {
            DB::table('project_assignments')->where('user_id', $fida->id)->delete();
            $fida->delete();
        }

        $gadRole = Role::where('slug', 'gen_ai_developer')->first();
        if ($gadRole) {
            DB::table('permissions')->where('role_id', $gadRole->id)->delete();
            DB::table('roles')->where('slug', 'gen_ai_developer')->delete();
        }

        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE meetings MODIFY COLUMN portal ENUM('ops', 'ceo', 'coo', 'cmo', 'cfo', 'marketing', 'product_manager', 'tech_lead', 'full_stack_developer', 'content_lead') NOT NULL");
        }
    }
};
