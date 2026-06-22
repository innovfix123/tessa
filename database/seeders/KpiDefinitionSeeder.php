<?php

namespace Database\Seeders;

use App\Models\KpiDefinition;
use App\Models\User;
use Illuminate\Database\Seeder;

class KpiDefinitionSeeder extends Seeder
{
    public function run(): void
    {
        if (KpiDefinition::count() > 0) {
            return;
        }

        $userSneha = User::where('email', 'sneha@innovfix.in')->first();
        $userAnirudh = User::where('email', 'anirudh@innovfix.in')->first();
        $userTamil = User::where('email', 'tamil@innovfix.in')->first();
        $userDhanush = User::where('email', 'dhanush@innovfix.in')->first();

        if (!$userSneha) {
            return;
        }

        $opsGroups = [
            ['name' => 'Creator Verification', 'fields' => [
                ['key' => 'applications_received', 'label' => 'Creator applications received', 'aggregation' => 'sum'],
                ['key' => 'applications_verified', 'label' => 'Creators verified (processed)', 'aggregation' => 'sum'],
                ['key' => 'avg_verification_time_hrs', 'label' => 'Avg verification turnaround (hours)', 'aggregation' => 'avg'],
                ['key' => 'active_creators', 'label' => 'Active creators', 'aggregation' => 'latest'],
            ]],
            ['name' => 'Support Tickets', 'fields' => [
                ['key' => 'avg_resolution_time_hrs', 'label' => 'Avg resolution time (hrs)', 'aggregation' => 'avg'],
            ]],
            ['name' => 'Call Quality', 'fields' => [
                ['key' => 'calls_tried', 'label' => 'Calls tried', 'aggregation' => 'sum'],
                ['key' => 'calls_connected_pct', 'label' => 'Call connect rate %', 'aggregation' => 'avg'],
            ]],
        ];

        $sortOrder = 0;
        foreach ($opsGroups as $group) {
            foreach ($group['fields'] as $field) {
                KpiDefinition::create([
                    'user_id' => $userSneha->id,
                    'group_name' => $group['name'],
                    'field_key' => $field['key'],
                    'field_label' => $field['label'],
                    'aggregation' => $field['aggregation'],
                    'sort_order' => $sortOrder++,
                ]);
            }
        }

        $marketingKpiPeople = [
            ['user_id' => $userTamil?->id, 'fields' => [
                ['key' => 'trial_users_completed_first_mock_24h', 'label' => 'Trial users who completed first mock test (24h)'],
                ['key' => 'trial_value_rate_24h_pct', 'label' => 'Trial value rate %'],
                ['key' => 'trial_starters', 'label' => 'Trial starters'],
            ]],
            ['user_id' => $userAnirudh?->id, 'fields' => [
                ['key' => 'daily_ad_spend', 'label' => 'Daily ad spend'],
                ['key' => 'cpa', 'label' => 'CPA'],
                ['key' => 'cpp', 'label' => 'CPP'],
                ['key' => 'new_installs', 'label' => 'New installs'],
                ['key' => 'registrations_from_paid', 'label' => 'Registrations from paid'],
                ['key' => 'paying_user_conversions', 'label' => 'Paying user conversions'],
                ['key' => 'active_campaigns', 'label' => 'Active campaigns'],
                ['key' => 'revenue_from_paid_users', 'label' => 'Revenue from paid users'],
            ]],
            ['user_id' => $userDhanush?->id, 'fields' => [
                ['key' => 'trial_starts', 'label' => 'Trial starts'],
                ['key' => 'd1_retention_pct', 'label' => 'D1 retention %'],
            ]],
            ['user_id' => $userSneha->id, 'fields' => [
                ['key' => 'applications_received', 'label' => 'Creator applications received'],
                ['key' => 'applications_verified', 'label' => 'Creators verified'],
                ['key' => 'avg_verification_time_hrs', 'label' => 'Avg verification time (hrs)'],
                ['key' => 'active_creators', 'label' => 'Active creators'],
                ['key' => 'avg_resolution_time_hrs', 'label' => 'Avg resolution time (hrs)'],
                ['key' => 'calls_tried', 'label' => 'Calls tried'],
                ['key' => 'calls_connected_pct', 'label' => 'Call connect rate %'],
            ]],
        ];

        $sortOrder = 0;
        foreach ($marketingKpiPeople as $person) {
            if (!$person['user_id']) continue;
            foreach ($person['fields'] as $field) {
                KpiDefinition::create([
                    'user_id' => $person['user_id'],
                    'group_name' => 'Metrics',
                    'field_key' => $field['key'],
                    'field_label' => $field['label'],
                    'aggregation' => null,
                    'sort_order' => $sortOrder++,
                ]);
            }
            $sortOrder = 0;
        }

        $teamKpis = [
            ['user_id' => $userTamil?->id, 'fields' => [
                ['key' => 'trial_to_premium_conversion_7d', 'label' => 'Trial to Premium Conversion (Within 7 Days)', 'aggregation' => 'latest'],
                ['key' => 'trial_value_rate_24h', 'label' => 'Trial Value Rate (Within 24 Hours)', 'aggregation' => 'latest'],
                ['key' => 'trial_d7_retention', 'label' => 'D7 Retention of Trial Users', 'aggregation' => 'latest'],
                ['key' => 'trial_mock_test_completion_rate', 'label' => 'Mock Test Completion Rate (Trial Users)', 'aggregation' => 'latest'],
            ]],
            ['user_id' => $userAnirudh?->id, 'fields' => [
                ['key' => 'daily_ad_spend', 'label' => 'Daily ad spend', 'aggregation' => 'sum'],
                ['key' => 'cpa', 'label' => 'CPA', 'aggregation' => 'avg'],
                ['key' => 'cpp', 'label' => 'CPP', 'aggregation' => 'avg'],
                ['key' => 'new_installs', 'label' => 'New installs', 'aggregation' => 'sum'],
                ['key' => 'registrations_from_paid', 'label' => 'Registrations from paid', 'aggregation' => 'sum'],
                ['key' => 'paying_user_conversions', 'label' => 'Paying user conversions', 'aggregation' => 'sum'],
                ['key' => 'active_campaigns', 'label' => 'Active campaigns', 'aggregation' => 'latest'],
                ['key' => 'revenue_from_paid_users', 'label' => 'Revenue from paid users', 'aggregation' => 'sum'],
            ]],
            ['user_id' => $userDhanush?->id, 'fields' => [
                ['key' => 'trial_starts', 'label' => 'Trial starts'],
                ['key' => 'd1_retention_pct', 'label' => 'D1 retention %'],
            ]],
        ];

        $sortOrder = 0;
        foreach ($teamKpis as $person) {
            if (!$person['user_id']) continue;
            foreach ($person['fields'] as $field) {
                KpiDefinition::create([
                    'user_id' => $person['user_id'],
                    'group_name' => 'Metrics',
                    'field_key' => $field['key'],
                    'field_label' => $field['label'],
                    'aggregation' => $field['aggregation'] ?? null,
                    'sort_order' => $sortOrder++,
                ]);
            }
            $sortOrder = 0;
        }
    }
}
