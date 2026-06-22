<?php

use App\Models\KpiDefinition;
use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $weekStart = Carbon::now('Asia/Kolkata')->startOfWeek(Carbon::MONDAY)->format('Y-m-d');

        $users = [
            ['id' => 54, 'group' => 'Daily Tasks'],  // Karuna
            ['id' => 32, 'group' => 'Daily Tasks'],  // Shoyab
            ['id' => 53, 'group' => 'QA Testing'],   // Iksha
        ];

        foreach ($users as $u) {
            $exists = KpiDefinition::where('user_id', $u['id'])
                ->where('field_key', 'blockers')
                ->exists();

            if (! $exists) {
                $maxSort = KpiDefinition::where('user_id', $u['id'])->max('sort_order') ?? -1;

                KpiDefinition::create([
                    'user_id' => $u['id'],
                    'group_name' => $u['group'],
                    'field_key' => 'blockers',
                    'field_label' => 'Blockers (Optional)',
                    'aggregation' => 'latest',
                    'input_type' => 'textarea',
                    'sort_order' => $maxSort + 1,
                    'created_by' => $u['id'],
                    'effective_from' => $weekStart,
                ]);
            }
        }
    }

    public function down(): void
    {
        KpiDefinition::whereIn('user_id', [54, 32, 53])
            ->where('field_key', 'blockers')
            ->forceDelete();
    }
};
