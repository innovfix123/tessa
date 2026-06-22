<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\Role;
use App\Models\KpiDefinition;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Create qa_analyst role
        $qaAnalystRole = DB::table('roles')->where('slug', 'qa_analyst')->first();
        if (!$qaAnalystRole) {
            $qaAnalystRoleId = DB::table('roles')->insertGetId([
                'name' => 'QA Analyst',
                'slug' => 'qa_analyst',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $qaAnalystRoleId = $qaAnalystRole->id;
        }

        // 2. Reassign Raksha from tech_lead to qa_analyst
        $raksha = User::where('email', 'raksha@innovfix.in')->first();
        if ($raksha) {
            DB::table('users')->where('id', $raksha->id)->update([
                'role_id' => $qaAnalystRoleId,
            ]);
        }

        // 3. Add permissions for qa_analyst
        $qaAnalystPermissions = [
            'feature.daily_reports',
            'feature.kpi',
            'daily_report.edit',
            'kpi.edit_entry',
            'feature.dashboard',
            'feature.meetings',
            'feature.calendar',
            'feature.org',
            'feature.templates',
            'template.manage',
            'meeting.access',
            'org.view',
        ];

        foreach ($qaAnalystPermissions as $permission) {
            $exists = DB::table('permissions')
                ->where('role_id', $qaAnalystRoleId)
                ->where('permission', $permission)
                ->exists();

            if (!$exists) {
                DB::table('permissions')->insert([
                    'role_id' => $qaAnalystRoleId,
                    'permission' => $permission,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // 4. Add permissions for tech_lead (daily reports, kpi, etc.)
        $techLeadRole = DB::table('roles')->where('slug', 'tech_lead')->first();
        if ($techLeadRole) {
            $techLeadNewPermissions = [
                'feature.daily_reports',
                'feature.kpi',
                'kpi.set_target',
                'kpi.manage_definitions',
            ];

            foreach ($techLeadNewPermissions as $permission) {
                $exists = DB::table('permissions')
                    ->where('role_id', $techLeadRole->id)
                    ->where('permission', $permission)
                    ->exists();

                if (!$exists) {
                    DB::table('permissions')->insert([
                        'role_id' => $techLeadRole->id,
                        'permission' => $permission,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }

        // 5. Seed KPI definitions for Raksha
        if ($raksha) {
            $qaFields = [
                ['key' => 'bugs_reported', 'label' => 'Bugs reported', 'aggregation' => 'sum'],
                ['key' => 'bugs_retested', 'label' => 'Bugs retested', 'aggregation' => 'sum'],
                ['key' => 'screens_flows_tested', 'label' => 'Screens/Flows tested', 'aggregation' => 'sum'],
                ['key' => 'builds_tested', 'label' => 'Builds tested', 'aggregation' => 'sum'],
                ['key' => 'blocked_hours', 'label' => 'Blocked hours (hrs)', 'aggregation' => 'sum'],
            ];

            $sortOrder = 0;
            foreach ($qaFields as $field) {
                $exists = KpiDefinition::where('user_id', $raksha->id)
                    ->where('field_key', $field['key'])
                    ->exists();

                if (!$exists) {
                    KpiDefinition::create([
                        'user_id' => $raksha->id,
                        'group_name' => 'QA Testing',
                        'field_key' => $field['key'],
                        'field_label' => $field['label'],
                        'aggregation' => $field['aggregation'],
                        'sort_order' => $sortOrder++,
                        'created_by' => $raksha->id,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        // Remove KPI definitions for Raksha
        $raksha = User::where('email', 'raksha@innovfix.in')->first();
        if ($raksha) {
            KpiDefinition::where('user_id', $raksha->id)->delete();
        }

        // Remove tech_lead new permissions
        $techLeadRole = Role::where('slug', 'tech_lead')->first();
        if ($techLeadRole) {
            DB::table('permissions')
                ->where('role_id', $techLeadRole->id)
                ->whereIn('permission', [
                    'feature.daily_reports',
                    'feature.kpi',
                    'kpi.set_target',
                    'kpi.manage_definitions',
                ])
                ->delete();
        }

        // Remove qa_analyst permissions and reassign Raksha to tech_lead
        $qaAnalystRole = Role::where('slug', 'qa_analyst')->first();
        if ($qaAnalystRole) {
            DB::table('permissions')->where('role_id', $qaAnalystRole->id)->delete();

            if ($raksha) {
                $techLeadRole = Role::where('slug', 'tech_lead')->first();
                if ($techLeadRole) {
                    DB::table('users')->where('id', $raksha->id)->update([
                        'role_id' => $techLeadRole->id,
                    ]);
                }
            }

            DB::table('roles')->where('slug', 'qa_analyst')->delete();
        }
    }
};
