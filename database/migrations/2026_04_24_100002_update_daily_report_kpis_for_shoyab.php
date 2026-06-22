<?php

use App\Models\KpiDefinition;
use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $userId = 32; // Shoyab
        $weekStart = Carbon::now('Asia/Kolkata')->startOfWeek(Carbon::MONDAY)->format('Y-m-d');

        // Remove old definitions
        KpiDefinition::where('user_id', $userId)->forceDelete();

        $fields = [
            ['field_key' => 'primary_tasks',   'field_label' => 'Primary Tasks',   'aggregation' => 'latest', 'input_type' => 'textarea', 'sort_order' => 0],
            ['field_key' => 'secondary_tasks', 'field_label' => 'Secondary Tasks', 'aggregation' => 'latest', 'input_type' => 'textarea', 'sort_order' => 1],
            ['field_key' => 'others',          'field_label' => 'Others',          'aggregation' => 'latest', 'input_type' => 'textarea', 'sort_order' => 2],
        ];

        foreach ($fields as $f) {
            KpiDefinition::create(array_merge($f, [
                'user_id' => $userId,
                'group_name' => 'Daily Tasks',
                'created_by' => $userId,
                'effective_from' => $weekStart,
            ]));
        }
    }

    public function down(): void
    {
        KpiDefinition::where('user_id', 32)
            ->whereIn('field_key', ['primary_tasks', 'secondary_tasks', 'others'])
            ->forceDelete();
    }
};
