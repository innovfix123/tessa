<?php

use App\Models\KpiDefinition;
use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $weekStart = Carbon::now('Asia/Kolkata')->startOfWeek(Carbon::MONDAY)->format('Y-m-d');

        $userIds = [38, 37, 35]; // Maari, Perumal, Rishabh

        $fields = [
            ['field_key' => 'tasks_completed', 'field_label' => 'Tasks Completed',   'aggregation' => 'sum', 'input_type' => 'text', 'sort_order' => 0],
            ['field_key' => 'tasks_pending',   'field_label' => 'Tasks Pending',     'aggregation' => 'latest', 'input_type' => 'text', 'sort_order' => 1],
            ['field_key' => 'bugs_backlogs',   'field_label' => 'Bugs / Backlogs',   'aggregation' => 'sum', 'input_type' => 'text', 'sort_order' => 2],
            ['field_key' => 'bugs_fixed',      'field_label' => 'Bugs Fixed',        'aggregation' => 'sum', 'input_type' => 'text', 'sort_order' => 3],
            ['field_key' => 'blockers',        'field_label' => 'Blockers (Optional)', 'aggregation' => 'latest', 'input_type' => 'textarea', 'sort_order' => 4],
        ];

        foreach ($userIds as $uid) {
            foreach ($fields as $f) {
                $exists = KpiDefinition::where('user_id', $uid)
                    ->where('field_key', $f['field_key'])
                    ->exists();

                if (! $exists) {
                    KpiDefinition::create(array_merge($f, [
                        'user_id' => $uid,
                        'group_name' => 'Daily Tasks',
                        'created_by' => $uid,
                        'effective_from' => $weekStart,
                    ]));
                }
            }
        }
    }

    public function down(): void
    {
        KpiDefinition::whereIn('user_id', [38, 37, 35])
            ->whereIn('field_key', ['tasks_completed', 'tasks_pending', 'bugs_backlogs', 'bugs_fixed', 'blockers'])
            ->forceDelete();
    }
};
