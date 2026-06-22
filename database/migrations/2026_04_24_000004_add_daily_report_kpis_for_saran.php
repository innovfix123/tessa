<?php

use App\Models\KpiDefinition;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $saran = User::where('email', 'saran@innovfix.in')->first();
        if (! $saran) {
            return;
        }

        $fields = [
            ['key' => 'what_did_you_do_today', 'label' => 'What did you do today?', 'aggregation' => 'latest', 'input_type' => 'textarea'],
            ['key' => 'problems_anomalies', 'label' => 'Problems/Anomalies', 'aggregation' => 'sum', 'input_type' => 'text'],
            ['key' => 'how_many_solved', 'label' => 'How many solved', 'aggregation' => 'sum', 'input_type' => 'text'],
            ['key' => 'comparison', 'label' => 'Comparison', 'aggregation' => 'latest', 'input_type' => 'textarea'],
            ['key' => 'blockers', 'label' => 'Blockers', 'aggregation' => 'latest', 'input_type' => 'textarea'],
        ];

        $sortOrder = 0;
        foreach ($fields as $field) {
            $exists = KpiDefinition::where('user_id', $saran->id)
                ->where('field_key', $field['key'])
                ->exists();

            if (! $exists) {
                KpiDefinition::create([
                    'user_id' => $saran->id,
                    'group_name' => 'Business Analyst',
                    'field_key' => $field['key'],
                    'field_label' => $field['label'],
                    'aggregation' => $field['aggregation'],
                    'input_type' => $field['input_type'],
                    'sort_order' => $sortOrder++,
                    'created_by' => $saran->id,
                ]);
            }
        }
    }

    public function down(): void
    {
        $saran = User::where('email', 'saran@innovfix.in')->first();
        if ($saran) {
            KpiDefinition::where('user_id', $saran->id)->delete();
        }
    }
};
