<?php

use App\Models\KpiDefinition;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $ranjini = User::where('email', 'ranjini@innovfix.in')->first();
        if (! $ranjini) {
            return;
        }

        $qaFields = [
            ['key' => 'bugs_reported', 'label' => 'Bugs reported', 'aggregation' => 'sum'],
            ['key' => 'bugs_retested', 'label' => 'Bugs retested', 'aggregation' => 'sum'],
            ['key' => 'screens_flows_tested', 'label' => 'Screens/Flows tested', 'aggregation' => 'sum'],
            ['key' => 'builds_tested', 'label' => 'Builds tested', 'aggregation' => 'sum'],
            ['key' => 'blocked_hours', 'label' => 'Blocked hours (hrs)', 'aggregation' => 'sum'],
        ];

        $sortOrder = 0;
        foreach ($qaFields as $field) {
            $exists = KpiDefinition::where('user_id', $ranjini->id)
                ->where('field_key', $field['key'])
                ->exists();

            if (! $exists) {
                KpiDefinition::create([
                    'user_id' => $ranjini->id,
                    'group_name' => 'QA Testing',
                    'field_key' => $field['key'],
                    'field_label' => $field['label'],
                    'aggregation' => $field['aggregation'],
                    'sort_order' => $sortOrder++,
                    'created_by' => $ranjini->id,
                ]);
            }
        }
    }

    public function down(): void
    {
        $ranjini = User::where('email', 'ranjini@innovfix.in')->first();
        if ($ranjini) {
            KpiDefinition::where('user_id', $ranjini->id)->delete();
        }
    }
};
