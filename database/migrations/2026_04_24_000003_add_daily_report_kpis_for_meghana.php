<?php

use App\Models\KpiDefinition;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $meghana = User::where('email', 'meghana@innovfix.in')->first();
        if (! $meghana) {
            return;
        }

        $groups = [
            'Business Analyst' => [
                ['key' => 'what_did_you_do_today', 'label' => 'What did you do today?', 'aggregation' => 'latest', 'input_type' => 'textarea'],
                ['key' => 'problems_anomalies', 'label' => 'Problems/Anomalies', 'aggregation' => 'sum', 'input_type' => 'text'],
                ['key' => 'how_many_solved', 'label' => 'How many solved', 'aggregation' => 'sum', 'input_type' => 'text'],
                ['key' => 'comparison', 'label' => 'Comparison', 'aggregation' => 'latest', 'input_type' => 'textarea'],
            ],
            'HR' => [
                ['key' => 'follow_ups', 'label' => 'Follow-ups', 'aggregation' => 'sum', 'input_type' => 'text'],
                ['key' => 'documentations', 'label' => 'Documentations', 'aggregation' => 'sum', 'input_type' => 'text'],
                ['key' => 'connect_with_finance_team', 'label' => 'Connect with Finance team', 'aggregation' => 'latest', 'input_type' => 'text'],
                ['key' => 'blockers', 'label' => 'Blockers', 'aggregation' => 'latest', 'input_type' => 'textarea'],
            ],
        ];

        $sortOrder = 0;
        foreach ($groups as $groupName => $fields) {
            foreach ($fields as $field) {
                $exists = KpiDefinition::where('user_id', $meghana->id)
                    ->where('field_key', $field['key'])
                    ->where('group_name', $groupName)
                    ->exists();

                if (! $exists) {
                    KpiDefinition::create([
                        'user_id' => $meghana->id,
                        'group_name' => $groupName,
                        'field_key' => $field['key'],
                        'field_label' => $field['label'],
                        'aggregation' => $field['aggregation'],
                        'input_type' => $field['input_type'],
                        'sort_order' => $sortOrder++,
                        'created_by' => $meghana->id,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        $meghana = User::where('email', 'meghana@innovfix.in')->first();
        if ($meghana) {
            KpiDefinition::where('user_id', $meghana->id)->delete();
        }
    }
};
