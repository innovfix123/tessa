<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        // Roles that get access to the agile feature
        $featureRoles = [
            'ceo', 'coo', 'tech_lead', 'qa_analyst',
            'full_stack_developer', 'gen_ai_developer', 'data_analyst',
        ];

        // Granular permissions per role
        $granularPermissions = [
            'tech_lead' => [
                'agile.manage_sprints',
                'agile.manage_epics',
                'agile.manage_squads',
                'agile.manage_labels',
                'agile.assign_items',
                'agile.crud_stories',
                'agile.crud_bugs',
                'agile.update_own_items',
                'agile.view_dashboard',
            ],
            'qa_analyst' => [
                'agile.assign_items',
                'agile.crud_stories',
                'agile.crud_bugs',
                'agile.update_own_items',
            ],
            'full_stack_developer' => [
                'agile.crud_stories',
                'agile.crud_bugs',
                'agile.update_own_items',
            ],
            'gen_ai_developer' => [
                'agile.crud_stories',
                'agile.crud_bugs',
                'agile.update_own_items',
            ],
            'data_analyst' => [
                'agile.crud_stories',
                'agile.crud_bugs',
                'agile.update_own_items',
            ],
            'ceo' => [
                'agile.view_dashboard',
            ],
            'coo' => [
                'agile.view_dashboard',
            ],
        ];

        foreach ($featureRoles as $slug) {
            $role = DB::table('roles')->where('slug', $slug)->first();
            if (! $role) {
                continue;
            }

            // Add feature.agile permission
            $exists = DB::table('permissions')
                ->where('role_id', $role->id)
                ->where('permission', 'feature.agile')
                ->exists();
            if (! $exists) {
                DB::table('permissions')->insert([
                    'role_id' => $role->id,
                    'permission' => 'feature.agile',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            // Add granular permissions
            $perms = $granularPermissions[$slug] ?? [];
            foreach ($perms as $perm) {
                $exists = DB::table('permissions')
                    ->where('role_id', $role->id)
                    ->where('permission', $perm)
                    ->exists();
                if (! $exists) {
                    DB::table('permissions')->insert([
                        'role_id' => $role->id,
                        'permission' => $perm,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        DB::table('permissions')->where('permission', 'feature.agile')->delete();
        DB::table('permissions')->where('permission', 'like', 'agile.%')->delete();
    }
};
