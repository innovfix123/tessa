<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\Role;
use App\Models\KpiDefinition;

return new class extends Migration
{
    private function addPermissionIfMissing(int $roleId, string $permission): void
    {
        $exists = DB::table('permissions')
            ->where('role_id', $roleId)
            ->where('permission', $permission)
            ->exists();

        if (!$exists) {
            DB::table('permissions')->insert([
                'role_id' => $roleId,
                'permission' => $permission,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function addKpiDefinitions(User $user, string $groupName, array $fields): void
    {
        $sortOrder = 0;
        foreach ($fields as $field) {
            $exists = KpiDefinition::where('user_id', $user->id)
                ->where('field_key', $field['key'])
                ->exists();

            if (!$exists) {
                KpiDefinition::create([
                    'user_id' => $user->id,
                    'group_name' => $groupName,
                    'field_key' => $field['key'],
                    'field_label' => $field['label'],
                    'aggregation' => $field['aggregation'],
                    'sort_order' => $sortOrder++,
                    'created_by' => $user->id,
                ]);
            }
        }
    }

    public function up(): void
    {
        $fsdRole = DB::table('roles')->where('slug', 'full_stack_developer')->first();
        $gadRole = DB::table('roles')->where('slug', 'gen_ai_developer')->first();
        $techLeadRole = DB::table('roles')->where('slug', 'tech_lead')->first();

        // 1. Add permissions for full_stack_developer
        if ($fsdRole) {
            foreach (['feature.daily_reports', 'feature.kpi', 'kpi.edit_entry', 'daily_report.edit'] as $perm) {
                $this->addPermissionIfMissing($fsdRole->id, $perm);
            }
        }

        // 2. Add permissions for gen_ai_developer
        if ($gadRole) {
            foreach (['feature.daily_reports', 'feature.kpi', 'kpi.edit_entry', 'daily_report.edit'] as $perm) {
                $this->addPermissionIfMissing($gadRole->id, $perm);
            }
        }

        // 3. Add kpi.edit_entry and daily_report.edit for tech_lead (to fill own reports)
        if ($techLeadRole) {
            foreach (['kpi.edit_entry', 'daily_report.edit'] as $perm) {
                $this->addPermissionIfMissing($techLeadRole->id, $perm);
            }
        }

        // 4. KPI definitions for Rishabh (Hima Development)
        $rishabh = User::where('email', 'rishabh@innovfix.in')->first();
        if ($rishabh) {
            $this->addKpiDefinitions($rishabh, 'Hima Development', [
                ['key' => 'features_delivered', 'label' => 'Features Delivered', 'aggregation' => 'sum'],
                ['key' => 'bugs_fixed', 'label' => 'Bugs Fixed', 'aggregation' => 'sum'],
                ['key' => 'open_bugs', 'label' => 'Open Bugs', 'aggregation' => 'latest'],
                ['key' => 'releases_shipped', 'label' => 'Releases Shipped', 'aggregation' => 'sum'],
                ['key' => 'crash_rate_pct', 'label' => 'Crash Rate (%)', 'aggregation' => 'latest'],
                ['key' => 'play_store_rating', 'label' => 'Play Store Rating', 'aggregation' => 'latest'],
            ]);
        }

        // 5. KPI definitions for Yuvanesh (Per-App Engineering Oversight)
        $yuvanesh = User::where('email', 'yuvanesh@innovfix.in')->first();
        if ($yuvanesh) {
            $this->addKpiDefinitions($yuvanesh, 'Hima', [
                ['key' => 'hima_releases', 'label' => 'Hima — Releases', 'aggregation' => 'sum'],
                ['key' => 'hima_open_bugs', 'label' => 'Hima — Open Bugs', 'aggregation' => 'latest'],
                ['key' => 'hima_status', 'label' => 'Hima — Status/Notes', 'aggregation' => 'latest'],
            ]);
            $this->addKpiDefinitions($yuvanesh, 'Only Care', [
                ['key' => 'onlycare_releases', 'label' => 'Only Care — Releases', 'aggregation' => 'sum'],
                ['key' => 'onlycare_open_bugs', 'label' => 'Only Care — Open Bugs', 'aggregation' => 'latest'],
                ['key' => 'onlycare_status', 'label' => 'Only Care — Status/Notes', 'aggregation' => 'latest'],
            ]);
            $this->addKpiDefinitions($yuvanesh, 'Sudar', [
                ['key' => 'sudar_releases', 'label' => 'Sudar — Releases', 'aggregation' => 'sum'],
                ['key' => 'sudar_open_bugs', 'label' => 'Sudar — Open Bugs', 'aggregation' => 'latest'],
                ['key' => 'sudar_status', 'label' => 'Sudar — Status/Notes', 'aggregation' => 'latest'],
            ]);
            $this->addKpiDefinitions($yuvanesh, 'Thedal', [
                ['key' => 'thedal_releases', 'label' => 'Thedal — Releases', 'aggregation' => 'sum'],
                ['key' => 'thedal_open_bugs', 'label' => 'Thedal — Open Bugs', 'aggregation' => 'latest'],
                ['key' => 'thedal_status', 'label' => 'Thedal — Status/Notes', 'aggregation' => 'latest'],
            ]);
            $this->addKpiDefinitions($yuvanesh, 'Astro', [
                ['key' => 'astro_releases', 'label' => 'Astro — Releases', 'aggregation' => 'sum'],
                ['key' => 'astro_open_bugs', 'label' => 'Astro — Open Bugs', 'aggregation' => 'latest'],
                ['key' => 'astro_status', 'label' => 'Astro — Status/Notes', 'aggregation' => 'latest'],
            ]);
        }

        // 6. KPI definitions for Sneha Prathap (Unman AI + Creators Platform)
        $snehaPrathap = User::where('email', 'snehaintern@innovfix.in')->first();
        if ($snehaPrathap) {
            $this->addKpiDefinitions($snehaPrathap, 'Unman AI', [
                ['key' => 'unman_features_delivered', 'label' => 'Unman AI — Features Delivered', 'aggregation' => 'sum'],
                ['key' => 'unman_bugs_fixed', 'label' => 'Unman AI — Bugs Fixed', 'aggregation' => 'sum'],
                ['key' => 'unman_releases_shipped', 'label' => 'Unman AI — Releases Shipped', 'aggregation' => 'sum'],
                ['key' => 'unman_open_bugs', 'label' => 'Unman AI — Open Bugs', 'aggregation' => 'latest'],
            ]);
            $this->addKpiDefinitions($snehaPrathap, 'Creators Platform', [
                ['key' => 'creators_features_delivered', 'label' => 'Creators Platform — Features Delivered', 'aggregation' => 'sum'],
                ['key' => 'creators_bugs_fixed', 'label' => 'Creators Platform — Bugs Fixed', 'aggregation' => 'sum'],
                ['key' => 'creators_releases_shipped', 'label' => 'Creators Platform — Releases Shipped', 'aggregation' => 'sum'],
                ['key' => 'creators_open_bugs', 'label' => 'Creators Platform — Open Bugs', 'aggregation' => 'latest'],
            ]);
        }
    }

    public function down(): void
    {
        $rishabh = User::where('email', 'rishabh@innovfix.in')->first();
        $yuvanesh = User::where('email', 'yuvanesh@innovfix.in')->first();
        $snehaPrathap = User::where('email', 'snehaintern@innovfix.in')->first();

        if ($rishabh) {
            KpiDefinition::where('user_id', $rishabh->id)->delete();
        }
        if ($yuvanesh) {
            KpiDefinition::where('user_id', $yuvanesh->id)->delete();
        }
        if ($snehaPrathap) {
            KpiDefinition::where('user_id', $snehaPrathap->id)->delete();
        }

        $fsdRole = Role::where('slug', 'full_stack_developer')->first();
        $gadRole = Role::where('slug', 'gen_ai_developer')->first();
        $techLeadRole = Role::where('slug', 'tech_lead')->first();

        if ($fsdRole) {
            DB::table('permissions')
                ->where('role_id', $fsdRole->id)
                ->whereIn('permission', ['feature.daily_reports', 'feature.kpi', 'kpi.edit_entry', 'daily_report.edit'])
                ->delete();
        }
        if ($gadRole) {
            DB::table('permissions')
                ->where('role_id', $gadRole->id)
                ->whereIn('permission', ['feature.daily_reports', 'feature.kpi', 'kpi.edit_entry', 'daily_report.edit'])
                ->delete();
        }
        if ($techLeadRole) {
            DB::table('permissions')
                ->where('role_id', $techLeadRole->id)
                ->whereIn('permission', ['kpi.edit_entry', 'daily_report.edit'])
                ->delete();
        }
    }
};
