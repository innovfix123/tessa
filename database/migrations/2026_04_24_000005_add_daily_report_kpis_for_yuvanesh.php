<?php

use App\Models\KpiDefinition;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $yuvanesh = User::where('email', 'yuvanesh@innovfix.in')->first();
        if (! $yuvanesh) {
            return;
        }

        $fields = [
            ['key' => 'sprints_monitored', 'label' => 'Sprints monitored', 'aggregation' => 'sum', 'input_type' => 'text'],
            ['key' => 'sprints_missed', 'label' => 'Sprints missed', 'aggregation' => 'sum', 'input_type' => 'text'],
            ['key' => 'meetings_attended', 'label' => 'Meetings attended', 'aggregation' => 'sum', 'input_type' => 'text'],
            ['key' => 'blockers', 'label' => 'Blockers', 'aggregation' => 'sum', 'input_type' => 'text'],
            ['key' => 'what_did_you_work_today', 'label' => 'What did you work today?', 'aggregation' => 'latest', 'input_type' => 'textarea'],
        ];

        $sortOrder = 0;
        foreach ($fields as $field) {
            $exists = KpiDefinition::where('user_id', $yuvanesh->id)
                ->where('field_key', $field['key'])
                ->exists();

            if (! $exists) {
                KpiDefinition::create([
                    'user_id' => $yuvanesh->id,
                    'group_name' => 'Engineering',
                    'field_key' => $field['key'],
                    'field_label' => $field['label'],
                    'aggregation' => $field['aggregation'],
                    'input_type' => $field['input_type'],
                    'sort_order' => $sortOrder++,
                    'created_by' => $yuvanesh->id,
                ]);
            }
        }
    }

    public function down(): void
    {
        $yuvanesh = User::where('email', 'yuvanesh@innovfix.in')->first();
        if ($yuvanesh) {
            KpiDefinition::where('user_id', $yuvanesh->id)->delete();
        }
    }
};
