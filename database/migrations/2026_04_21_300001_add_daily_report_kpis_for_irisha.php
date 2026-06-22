<?php

use App\Models\KpiDefinition;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $roleId = DB::table('roles')->where('slug', 'founders_office')->value('id');
        if (! $roleId) {
            return;
        }

        $permissions = [
            'feature.daily_reports',
            'daily_report.edit',
            'feature.dashboard',
            'feature.meetings',
            'feature.calendar',
            'feature.org',
            'feature.templates',
            'template.manage',
            'meeting.access',
            'org.view',
        ];

        foreach ($permissions as $permission) {
            $exists = DB::table('permissions')
                ->where('role_id', $roleId)
                ->where('permission', $permission)
                ->exists();

            if (! $exists) {
                DB::table('permissions')->insert([
                    'role_id' => $roleId,
                    'permission' => $permission,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        $irisha = User::where('email', 'irisha@innovfix.in')->first();
        if (! $irisha) {
            return;
        }

        $fields = [
            ['key' => 'outreach_done', 'label' => 'Outreach done (people)', 'aggregation' => 'sum'],
            ['key' => 'replies_received', 'label' => 'Replies received', 'aggregation' => 'sum'],
            ['key' => 'positive_replies', 'label' => 'Positive replies', 'aggregation' => 'sum'],
            ['key' => 'meetings_booked', 'label' => 'Meetings booked', 'aggregation' => 'sum'],
            ['key' => 'key_update', 'label' => 'Key update', 'aggregation' => 'latest', 'input_type' => 'textarea'],
        ];

        $sortOrder = 0;
        foreach ($fields as $field) {
            $exists = KpiDefinition::where('user_id', $irisha->id)
                ->where('field_key', $field['key'])
                ->exists();

            if (! $exists) {
                KpiDefinition::create([
                    'user_id' => $irisha->id,
                    'group_name' => 'Daily Outreach',
                    'field_key' => $field['key'],
                    'field_label' => $field['label'],
                    'aggregation' => $field['aggregation'],
                    'input_type' => $field['input_type'] ?? 'text',
                    'sort_order' => $sortOrder++,
                    'created_by' => $irisha->id,
                ]);
            }
        }
    }

    public function down(): void
    {
        $irisha = User::where('email', 'irisha@innovfix.in')->first();
        if ($irisha) {
            KpiDefinition::where('user_id', $irisha->id)->delete();
        }

        $roleId = DB::table('roles')->where('slug', 'founders_office')->value('id');
        if ($roleId) {
            DB::table('permissions')
                ->where('role_id', $roleId)
                ->whereIn('permission', [
                    'feature.daily_reports',
                    'daily_report.edit',
                    'feature.dashboard',
                    'feature.meetings',
                    'feature.calendar',
                    'feature.org',
                    'feature.templates',
                    'template.manage',
                    'meeting.access',
                    'org.view',
                ])
                ->delete();
        }
    }
};
