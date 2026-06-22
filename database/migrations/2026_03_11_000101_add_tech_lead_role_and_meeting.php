<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\Role;
use App\Models\Meeting;
use App\Models\AgendaTemplate;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Insert tech_lead role (or get existing)
        $techLeadRole = DB::table('roles')->where('slug', 'tech_lead')->first();
        if (!$techLeadRole) {
            $techLeadRoleId = DB::table('roles')->insertGetId([
                'name' => 'Tech Lead',
                'slug' => 'tech_lead',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $techLeadRoleId = $techLeadRole->id;
        }

        // 2. Assign Yuvanesh to tech_lead role (if user exists)
        $yuvanesh = User::where('email', 'yuvanesh@innovfix.in')->first();
        if ($yuvanesh) {
            DB::table('users')->where('id', $yuvanesh->id)->update([
                'role_id' => $techLeadRoleId,
                'updated_at' => now(),
            ]);
        }
        // Note: If Yuvanesh user doesn't exist, role assignment will be skipped.
        // User should be created separately and then assigned to tech_lead role.

        // 3. Add 'tech_lead' to meetings.portal enum (MySQL)
        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE meetings MODIFY COLUMN portal ENUM('ops', 'ceo', 'coo', 'cmo', 'cfo', 'marketing', 'product_manager', 'tech_lead') NOT NULL");
        }

        // 4. Insert permissions for tech_lead role (skip if already exists)
        $permissions = [
            'meeting.access',
            'feature.meetings',
            'feature.dashboard',
            'template.manage',
            'org.view',
            'feature.org',
            'feature.templates',
        ];

        foreach ($permissions as $permission) {
            $exists = DB::table('permissions')
                ->where('role_id', $techLeadRoleId)
                ->where('permission', $permission)
                ->exists();
            
            if (!$exists) {
                DB::table('permissions')->insert([
                    'role_id' => $techLeadRoleId,
                    'permission' => $permission,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // 5. Create the Hima Standup meeting
        if ($yuvanesh) {
            $rishabh = User::where('email', 'rishabh@innovfix.in')->first();
            $raksha = User::where('email', 'raksha@innovfix.in')->first();

            if ($rishabh && $raksha) {
                // Find or get the existing "Stand-up Meeting" template
                $template = AgendaTemplate::where('name', 'Stand-up Meeting')->first();

                Meeting::updateOrCreate(
                    ['meeting_key' => 'tech-lead-hima-standup'],
                    [
                        'title' => 'Hima Standup',
                        'owner' => $yuvanesh->name,
                        'owner_id' => $yuvanesh->id,
                        'day_of_week' => 'Monday',
                        'time' => '10:30 AM',
                        'recurrence' => 'daily_weekdays',
                        'portal' => 'tech_lead',
                        'attendees' => [$rishabh->id, $raksha->id],
                        'agenda_template_id' => $template?->id,
                        'created_by' => $yuvanesh->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }
        }
    }

    public function down(): void
    {
        // Delete the meeting
        Meeting::where('meeting_key', 'tech-lead-hima-standup')->delete();

        // Remove permissions
        $techLeadRole = Role::where('slug', 'tech_lead')->first();
        if ($techLeadRole) {
            DB::table('permissions')->where('role_id', $techLeadRole->id)->delete();
        }

        // Revert Yuvanesh's role (set to null or previous role if known)
        $yuvanesh = User::where('email', 'yuvanesh@innovfix.in')->first();
        if ($yuvanesh) {
            // Set to null for now - can be updated if we know previous role
            DB::table('users')->where('id', $yuvanesh->id)->update([
                'role_id' => null,
                'updated_at' => now(),
            ]);
        }

        // Delete tech_lead role
        DB::table('roles')->where('slug', 'tech_lead')->delete();

        // Revert meetings portal enum
        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE meetings MODIFY COLUMN portal ENUM('ops', 'ceo', 'coo', 'cmo', 'cfo', 'marketing', 'product_manager') NOT NULL");
        }
    }
};
