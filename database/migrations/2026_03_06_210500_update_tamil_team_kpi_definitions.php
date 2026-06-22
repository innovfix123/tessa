<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            DB::table('kpi_definitions')
                ->where('scope', 'team')
                ->where('person_id', 'tamil-sudar')
                ->whereNotIn('field_key', ['_person_init', '_placeholder', '_group_init'])
                ->delete();

            $now = now();
            $rows = [
                [
                    'scope' => 'team',
                    'person_id' => 'tamil-sudar',
                    'person_name' => 'Tamil Arasan',
                    'person_role' => 'Sudar PM',
                    'project_name' => 'Sudar',
                    'group_name' => 'Metrics',
                    'field_key' => 'trial_to_premium_conversion_7d',
                    'field_label' => 'Trial to Premium Conversion (Within 7 Days)',
                    'aggregation' => 'latest',
                    'sort_order' => 0,
                    'created_by' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'scope' => 'team',
                    'person_id' => 'tamil-sudar',
                    'person_name' => 'Tamil Arasan',
                    'person_role' => 'Sudar PM',
                    'project_name' => 'Sudar',
                    'group_name' => 'Metrics',
                    'field_key' => 'trial_value_rate_24h',
                    'field_label' => 'Trial Value Rate (Within 24 Hours)',
                    'aggregation' => 'latest',
                    'sort_order' => 1,
                    'created_by' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'scope' => 'team',
                    'person_id' => 'tamil-sudar',
                    'person_name' => 'Tamil Arasan',
                    'person_role' => 'Sudar PM',
                    'project_name' => 'Sudar',
                    'group_name' => 'Metrics',
                    'field_key' => 'trial_d7_retention',
                    'field_label' => 'D7 Retention of Trial Users',
                    'aggregation' => 'latest',
                    'sort_order' => 2,
                    'created_by' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'scope' => 'team',
                    'person_id' => 'tamil-sudar',
                    'person_name' => 'Tamil Arasan',
                    'person_role' => 'Sudar PM',
                    'project_name' => 'Sudar',
                    'group_name' => 'Metrics',
                    'field_key' => 'trial_mock_test_completion_rate',
                    'field_label' => 'Mock Test Completion Rate (Trial Users)',
                    'aggregation' => 'latest',
                    'sort_order' => 3,
                    'created_by' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ];

            DB::table('kpi_definitions')->insert($rows);
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            DB::table('kpi_definitions')
                ->where('scope', 'team')
                ->where('person_id', 'tamil-sudar')
                ->whereIn('field_key', [
                    'trial_to_premium_conversion_7d',
                    'trial_value_rate_24h',
                    'trial_d7_retention',
                    'trial_mock_test_completion_rate',
                ])
                ->delete();

            $now = now();
            DB::table('kpi_definitions')->insert([
                [
                    'scope' => 'team',
                    'person_id' => 'tamil-sudar',
                    'person_name' => 'Tamil Arasan',
                    'person_role' => 'Sudar PM',
                    'project_name' => 'Sudar',
                    'group_name' => 'Metrics',
                    'field_key' => 'trial_users_completed_first_mock_24h',
                    'field_label' => 'Trial users who completed first mock test (24h)',
                    'aggregation' => null,
                    'sort_order' => 0,
                    'created_by' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'scope' => 'team',
                    'person_id' => 'tamil-sudar',
                    'person_name' => 'Tamil Arasan',
                    'person_role' => 'Sudar PM',
                    'project_name' => 'Sudar',
                    'group_name' => 'Metrics',
                    'field_key' => 'trial_value_rate_24h_pct',
                    'field_label' => 'Trial value rate %',
                    'aggregation' => null,
                    'sort_order' => 1,
                    'created_by' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ]);
        });
    }
};
