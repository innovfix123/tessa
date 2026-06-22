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
        // Get tech_lead role ID
        $techLeadRole = Role::where('slug', 'tech_lead')->first();
        if (!$techLeadRole) {
            return; // Role should exist from previous migration
        }
        $techLeadRoleId = $techLeadRole->id;

        // Get JP's user ID (reporting manager for Yuvanesh)
        $jp = User::where('email', 'jp@innovfix.in')->first();
        if (!$jp) {
            return; // JP should exist
        }
        $jpId = $jp->id;

        // Password hash for all users
        $passwordHash = password_hash('12345678', PASSWORD_BCRYPT);

        // 1. Create Yuvanesh
        $yuvanesh = User::updateOrCreate(
            ['email' => 'yuvanesh@innovfix.in'],
            [
                'name' => 'Yuvanesh',
                'password_hash' => $passwordHash,
                'role_id' => $techLeadRoleId,
                'reporting_manager_id' => $jpId,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
        $yuvaneshId = $yuvanesh->id;

        // 2. Create Rishabh (reports to Yuvanesh)
        $rishabh = User::updateOrCreate(
            ['email' => 'rishabh@innovfix.in'],
            [
                'name' => 'Rishabh',
                'password_hash' => $passwordHash,
                'role_id' => $techLeadRoleId,
                'reporting_manager_id' => $yuvaneshId,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
        $rishabhId = $rishabh->id;

        // 3. Create Raksha (reports to Yuvanesh)
        $raksha = User::updateOrCreate(
            ['email' => 'raksha@innovfix.in'],
            [
                'name' => 'Raksha',
                'password_hash' => $passwordHash,
                'role_id' => $techLeadRoleId,
                'reporting_manager_id' => $yuvaneshId,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
        $rakshaId = $raksha->id;

        // 4. Assign all three to Hima project (ID: 1)
        $himaProjectId = 1;
        $userIds = [$yuvaneshId, $rishabhId, $rakshaId];
        
        foreach ($userIds as $userId) {
            $exists = DB::table('project_assignments')
                ->where('user_id', $userId)
                ->where('project_id', $himaProjectId)
                ->exists();
            
            if (!$exists) {
                DB::table('project_assignments')->insert([
                    'user_id' => $userId,
                    'project_id' => $himaProjectId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // 5. Create the Hima Standup meeting
        $template = AgendaTemplate::where('name', 'Stand-up Meeting')->first();

        Meeting::updateOrCreate(
            ['meeting_key' => 'tech-lead-hima-standup'],
            [
                'title' => 'Hima Standup',
                'owner' => $yuvanesh->name,
                'owner_id' => $yuvaneshId,
                'day_of_week' => 'Monday',
                'time' => '10:30 AM',
                'recurrence' => 'daily_weekdays',
                'portal' => 'tech_lead',
                'attendees' => [$rishabhId, $rakshaId],
                'agenda_template_id' => $template?->id,
                'created_by' => $yuvaneshId,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        // Delete the meeting
        Meeting::where('meeting_key', 'tech-lead-hima-standup')->delete();

        // Remove project assignments
        $emails = ['yuvanesh@innovfix.in', 'rishabh@innovfix.in', 'raksha@innovfix.in'];
        $userIds = User::whereIn('email', $emails)->pluck('id')->toArray();
        
        if (!empty($userIds)) {
            DB::table('project_assignments')
                ->whereIn('user_id', $userIds)
                ->where('project_id', 1)
                ->delete();
        }

        // Delete users
        User::whereIn('email', $emails)->delete();
    }
};
