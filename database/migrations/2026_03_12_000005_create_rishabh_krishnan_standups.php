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
        $rishabh = User::where('email', 'rishabh@innovfix.in')->first();
        $krishnan = User::where('email', 'krishnan@innovfix.in')->first();
        if (!$rishabh || !$krishnan) {
            return;
        }

        $passwordHash = password_hash('12345678', PASSWORD_BCRYPT);

        // 1. Create full_stack_developer role
        $fsdRole = DB::table('roles')->where('slug', 'full_stack_developer')->first();
        if (!$fsdRole) {
            $fsdRoleId = DB::table('roles')->insertGetId([
                'name' => 'Full Stack Developer',
                'slug' => 'full_stack_developer',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $fsdRoleId = $fsdRole->id;
        }

        // 2. Reassign Rishabh from tech_lead to full_stack_developer
        DB::table('users')->where('id', $rishabh->id)->update([
            'role_id' => $fsdRoleId,
        ]);

        // 3. Add full_stack_developer and content_lead to meetings portal enum
        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE meetings MODIFY COLUMN portal ENUM('ops', 'ceo', 'coo', 'cmo', 'cfo', 'marketing', 'product_manager', 'tech_lead', 'full_stack_developer', 'content_lead') NOT NULL");
        }

        // 4. Add permissions for full_stack_developer
        $fsdPermissions = [
            'meeting.access',
            'feature.meetings',
            'feature.dashboard',
            'feature.calendar',
            'feature.org',
            'feature.templates',
            'template.manage',
            'org.view',
        ];
        foreach ($fsdPermissions as $permission) {
            $exists = DB::table('permissions')
                ->where('role_id', $fsdRoleId)
                ->where('permission', $permission)
                ->exists();
            if (!$exists) {
                DB::table('permissions')->insert([
                    'role_id' => $fsdRoleId,
                    'permission' => $permission,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // 5. Create Barkha user
        $barkha = User::updateOrCreate(
            ['email' => 'barkha@innovfix.in'],
            [
                'name' => 'Barkha Agarwal',
                'password_hash' => $passwordHash,
                'role_id' => $fsdRoleId,
                'reporting_manager_id' => $rishabh->id,
                'is_active' => true,
            ]
        );

        $barkhaProjectExists = DB::table('project_assignments')
            ->where('user_id', $barkha->id)
            ->where('project_id', 1)
            ->exists();
        if (!$barkhaProjectExists) {
            DB::table('project_assignments')->insert([
                'user_id' => $barkha->id,
                'project_id' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 6. Create Disha user
        $contentLeadRole = Role::where('slug', 'content_lead')->first();
        if (!$contentLeadRole) {
            return;
        }

        $disha = User::updateOrCreate(
            ['email' => 'disha@innovfix.in'],
            [
                'name' => 'Disha',
                'password_hash' => $passwordHash,
                'role_id' => $contentLeadRole->id,
                'reporting_manager_id' => $krishnan->id,
                'is_active' => true,
            ]
        );

        $dishaProjectExists = DB::table('project_assignments')
            ->where('user_id', $disha->id)
            ->where('project_id', 1)
            ->exists();
        if (!$dishaProjectExists) {
            DB::table('project_assignments')->insert([
                'user_id' => $disha->id,
                'project_id' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 7. Create agenda templates
        $astroItems = [
            ['section_title' => "YESTERDAY'S WORK", 'point_question' => null],
            ['section_title' => '', 'point_question' => "What tasks were completed yesterday?"],
            ['section_title' => "TODAY'S PLAN", 'point_question' => null],
            ['section_title' => '', 'point_question' => "What are you working on today?"],
            ['section_title' => 'APP UPDATES', 'point_question' => null],
            ['section_title' => '', 'point_question' => 'Any issues or bugs in the Astro app?'],
            ['section_title' => 'BLOCKERS', 'point_question' => null],
            ['section_title' => '', 'point_question' => 'Any blockers or dependencies?'],
        ];

        $astroTemplate = AgendaTemplate::firstOrCreate(
            ['name' => 'Astro App Standup'],
            ['created_by' => $rishabh->id]
        );
        if ($astroTemplate->items()->count() === 0) {
            $sortOrder = 1;
            foreach ($astroItems as $item) {
                AgendaTemplateItem::create([
                    'template_id' => $astroTemplate->id,
                    'section_title' => $item['section_title'],
                    'point_question' => $item['point_question'],
                    'sort_order' => $sortOrder++,
                ]);
            }
        }

        $contentItems = [
            ['section_title' => "YESTERDAY'S WORK", 'point_question' => null],
            ['section_title' => '', 'point_question' => 'What content was created/published yesterday?'],
            ['section_title' => "TODAY'S PLAN", 'point_question' => null],
            ['section_title' => '', 'point_question' => 'What content is planned for today?'],
            ['section_title' => 'CONTENT PIPELINE', 'point_question' => null],
            ['section_title' => '', 'point_question' => 'Any scripts or assets pending review?'],
            ['section_title' => 'BLOCKERS', 'point_question' => null],
            ['section_title' => '', 'point_question' => 'Any blockers or dependencies?'],
        ];

        $contentTemplate = AgendaTemplate::firstOrCreate(
            ['name' => 'Content Standup'],
            ['created_by' => $krishnan->id]
        );
        if ($contentTemplate->items()->count() === 0) {
            $sortOrder = 1;
            foreach ($contentItems as $item) {
                AgendaTemplateItem::create([
                    'template_id' => $contentTemplate->id,
                    'section_title' => $item['section_title'],
                    'point_question' => $item['point_question'],
                    'sort_order' => $sortOrder++,
                ]);
            }
        }

        // 8. Create meetings
        Meeting::updateOrCreate(
            ['meeting_key' => 'full-stack-dev-astro-standup'],
            [
                'title' => 'Astro App Standup',
                'owner' => $rishabh->name,
                'owner_id' => $rishabh->id,
                'day_of_week' => 'Monday',
                'time' => '11:00 AM',
                'recurrence' => 'daily_weekdays',
                'portal' => 'full_stack_developer',
                'attendees' => [$barkha->id],
                'agenda_template_id' => $astroTemplate->id,
                'created_by' => $rishabh->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        Meeting::updateOrCreate(
            ['meeting_key' => 'content-lead-disha-standup'],
            [
                'title' => 'Content Standup',
                'owner' => $krishnan->name,
                'owner_id' => $krishnan->id,
                'day_of_week' => 'Monday',
                'time' => '10:30 AM',
                'recurrence' => 'daily_weekdays',
                'portal' => 'content_lead',
                'attendees' => [$disha->id],
                'agenda_template_id' => $contentTemplate->id,
                'created_by' => $krishnan->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        Meeting::where('meeting_key', 'full-stack-dev-astro-standup')->delete();
        Meeting::where('meeting_key', 'content-lead-disha-standup')->delete();

        $astroTemplate = AgendaTemplate::where('name', 'Astro App Standup')->first();
        if ($astroTemplate) {
            AgendaTemplateItem::where('template_id', $astroTemplate->id)->delete();
            $astroTemplate->delete();
        }

        $contentTemplate = AgendaTemplate::where('name', 'Content Standup')->first();
        if ($contentTemplate) {
            AgendaTemplateItem::where('template_id', $contentTemplate->id)->delete();
            $contentTemplate->delete();
        }

        $barkha = User::where('email', 'barkha@innovfix.in')->first();
        $disha = User::where('email', 'disha@innovfix.in')->first();

        if ($barkha) {
            DB::table('project_assignments')->where('user_id', $barkha->id)->delete();
            $barkha->delete();
        }
        if ($disha) {
            DB::table('project_assignments')->where('user_id', $disha->id)->delete();
            $disha->delete();
        }

        $rishabh = User::where('email', 'rishabh@innovfix.in')->first();
        $techLeadRole = Role::where('slug', 'tech_lead')->first();
        if ($rishabh && $techLeadRole) {
            DB::table('users')->where('id', $rishabh->id)->update(['role_id' => $techLeadRole->id]);
        }

        $fsdRole = Role::where('slug', 'full_stack_developer')->first();
        if ($fsdRole) {
            DB::table('permissions')->where('role_id', $fsdRole->id)->delete();
            DB::table('roles')->where('slug', 'full_stack_developer')->delete();
        }

        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE meetings MODIFY COLUMN portal ENUM('ops', 'ceo', 'coo', 'cmo', 'cfo', 'marketing', 'product_manager', 'tech_lead') NOT NULL");
        }
    }
};
