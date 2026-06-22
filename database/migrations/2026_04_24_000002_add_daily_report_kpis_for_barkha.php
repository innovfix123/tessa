<?php

use App\Models\KpiDefinition;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $barkha = User::where('email', 'barkha@innovfix.in')->first();
        if (! $barkha) {
            return;
        }

        $fields = [
            ['key' => 'tasks_completed', 'label' => 'Tasks completed', 'aggregation' => 'sum'],
            ['key' => 'tasks_pending', 'label' => 'Tasks pending', 'aggregation' => 'sum'],
            ['key' => 'blockers', 'label' => 'Blockers', 'aggregation' => 'sum'],
        ];

        $sortOrder = 0;
        foreach ($fields as $field) {
            $exists = KpiDefinition::where('user_id', $barkha->id)
                ->where('field_key', $field['key'])
                ->exists();

            if (! $exists) {
                KpiDefinition::create([
                    'user_id' => $barkha->id,
                    'group_name' => 'Daily Tasks',
                    'field_key' => $field['key'],
                    'field_label' => $field['label'],
                    'aggregation' => $field['aggregation'],
                    'sort_order' => $sortOrder++,
                    'created_by' => $barkha->id,
                ]);
            }
        }
    }

    public function down(): void
    {
        $barkha = User::where('email', 'barkha@innovfix.in')->first();
        if ($barkha) {
            KpiDefinition::where('user_id', $barkha->id)->delete();
        }
    }
};
