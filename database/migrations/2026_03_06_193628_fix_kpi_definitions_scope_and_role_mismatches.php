<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Fix stale role names: "Sudar PM" -> "Product Manager", "Thedal PM" -> "Product Manager"
        DB::table('kpi_definitions')
            ->where('person_id', 'tamil-sudar')
            ->update([
                'person_role' => 'Product Manager',
                'project_name' => 'Sudar',
            ]);

        DB::table('kpi_definitions')
            ->where('person_id', 'dhanush-thedal')
            ->update([
                'person_role' => 'Product Manager',
                'project_name' => 'Thedal',
            ]);

        // 2. Fix sneha-ops: update mkpi scope to use correct role/project
        DB::table('kpi_definitions')
            ->where('person_id', 'sneha-ops')
            ->update([
                'person_role' => 'Operations Manager',
                'project_name' => 'Hima',
            ]);

        // 3. Add sneha-ops to "team" scope (missing entirely)
        $snehaTeamExists = DB::table('kpi_definitions')
            ->where('scope', 'team')
            ->where('person_id', 'sneha-ops')
            ->exists();

        if (!$snehaTeamExists) {
            $snehaOpsFields = [
                ['group' => 'Creator Verification', 'key' => 'applications_received', 'label' => 'Creator applications received', 'agg' => 'sum'],
                ['group' => 'Creator Verification', 'key' => 'applications_verified', 'label' => 'Creators verified (processed)', 'agg' => 'sum'],
                ['group' => 'Creator Verification', 'key' => 'avg_verification_time_hrs', 'label' => 'Avg verification turnaround (hours)', 'agg' => 'avg'],
                ['group' => 'Creator Verification', 'key' => 'active_creators', 'label' => 'Active creators', 'agg' => 'latest'],
                ['group' => 'Support Tickets', 'key' => 'avg_resolution_time_hrs', 'label' => 'Avg resolution time (hrs)', 'agg' => 'avg'],
                ['group' => 'Call Quality', 'key' => 'calls_tried', 'label' => 'Calls tried', 'agg' => 'sum'],
                ['group' => 'Call Quality', 'key' => 'calls_connected_pct', 'label' => 'Call connect rate %', 'agg' => 'avg'],
            ];

            $sortOrder = 0;
            foreach ($snehaOpsFields as $field) {
                DB::table('kpi_definitions')->insert([
                    'scope' => 'team',
                    'person_id' => 'sneha-ops',
                    'person_name' => 'Sneha',
                    'person_role' => 'Operations Manager',
                    'project_name' => 'Hima',
                    'group_name' => $field['group'],
                    'field_key' => $field['key'],
                    'field_label' => $field['label'],
                    'aggregation' => $field['agg'],
                    'sort_order' => $sortOrder++,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // 4. Add dhanush-thedal to "mkpi" scope (missing for CEO view)
        $dhanushMkpiExists = DB::table('kpi_definitions')
            ->where('scope', 'mkpi')
            ->where('person_id', 'dhanush-thedal')
            ->exists();

        if (!$dhanushMkpiExists) {
            $dhanushFields = [
                ['key' => 'trial_starts', 'label' => 'Trial starts'],
                ['key' => 'd1_retention_pct', 'label' => 'D1 retention %'],
            ];

            $sortOrder = 0;
            foreach ($dhanushFields as $field) {
                DB::table('kpi_definitions')->insert([
                    'scope' => 'mkpi',
                    'person_id' => 'dhanush-thedal',
                    'person_name' => 'Dhanush',
                    'person_role' => 'Product Manager',
                    'project_name' => 'Thedal',
                    'group_name' => 'Metrics',
                    'field_key' => $field['key'],
                    'field_label' => $field['label'],
                    'aggregation' => null,
                    'sort_order' => $sortOrder++,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // 5. Remove legacy anirudh-mktg definitions (replaced by anirudh-marketing)
        DB::table('kpi_definitions')
            ->where('person_id', 'anirudh-mktg')
            ->delete();

        // 6. Merge orphan person_ids into anirudh-marketing (handle collisions)
        $this->mergePersonData('anirudh', 'anirudh-marketing');
        $this->mergePersonData('anirudh-mktg', 'anirudh-marketing');

        // 8. Fix anirudh-marketing role/project in mkpi definitions
        DB::table('kpi_definitions')
            ->where('person_id', 'anirudh-marketing')
            ->update([
                'person_role' => 'Performance Marketing',
                'project_name' => 'Hima',
            ]);

        // 9. Deactivate duplicate Sneha user (keep id=5 as primary)
        DB::table('users')
            ->where('id', 10)
            ->where('email', 'ops@innovfix.in')
            ->update(['is_active' => false]);
    }

    /**
     * Merge entries/targets from $fromId into $toId, preferring non-empty values on collision, then delete leftovers.
     */
    private function mergePersonData(string $fromId, string $toId): void
    {
        foreach (['kpi_entries', 'kpi_targets'] as $table) {
            $rows = DB::table($table)->where('person_id', $fromId)->get();
            foreach ($rows as $row) {
                $existing = DB::table($table)
                    ->where('person_id', $toId)
                    ->where('week_key', $row->week_key)
                    ->where('field_key', $row->field_key)
                    ->first();

                if ($existing) {
                    // Collision: keep whichever has a non-empty value
                    if (($existing->value === '' || $existing->value === null) && $row->value !== '' && $row->value !== null) {
                        DB::table($table)->where('id', $existing->id)->update(['value' => $row->value]);
                    }
                    DB::table($table)->where('id', $row->id)->delete();
                } else {
                    DB::table($table)->where('id', $row->id)->update(['person_id' => $toId]);
                }
            }
        }

        // Also merge CEO notes
        $notes = DB::table('kpi_ceo_notes')->where('person_id', $fromId)->get();
        foreach ($notes as $note) {
            $existing = DB::table('kpi_ceo_notes')
                ->where('person_id', $toId)
                ->where('week_key', $note->week_key)
                ->first();

            if ($existing) {
                if (($existing->note === '' || $existing->note === null) && $note->note !== '' && $note->note !== null) {
                    DB::table('kpi_ceo_notes')->where('id', $existing->id)->update(['note' => $note->note]);
                }
                DB::table('kpi_ceo_notes')->where('id', $note->id)->delete();
            } else {
                DB::table('kpi_ceo_notes')->where('id', $note->id)->update(['person_id' => $toId]);
            }
        }
    }

    public function down(): void
    {
        // Re-activate duplicate Sneha
        DB::table('users')
            ->where('id', 10)
            ->update(['is_active' => true]);

        // Remove team-scope sneha-ops rows we added
        DB::table('kpi_definitions')
            ->where('scope', 'team')
            ->where('person_id', 'sneha-ops')
            ->delete();

        // Remove mkpi-scope dhanush-thedal rows we added
        DB::table('kpi_definitions')
            ->where('scope', 'mkpi')
            ->where('person_id', 'dhanush-thedal')
            ->delete();
    }
};
