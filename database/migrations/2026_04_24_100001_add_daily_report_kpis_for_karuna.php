<?php

use App\Models\KpiDefinition;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $karuna = User::where('email', 'karuna@innovfix.in')->first();
        if (! $karuna) {
            return;
        }

        $weekStart = Carbon::now('Asia/Kolkata')->startOfWeek(Carbon::MONDAY)->format('Y-m-d');

        $fields = [
            ['field_key' => 'invoice_management', 'field_label' => 'Invoice Management', 'aggregation' => 'latest', 'input_type' => 'status', 'sort_order' => 0],
            ['field_key' => 'accounting',          'field_label' => 'Accounting',          'aggregation' => 'latest', 'input_type' => 'status', 'sort_order' => 1],
            ['field_key' => 'entry_checking',      'field_label' => 'Entry Checking',      'aggregation' => 'latest', 'input_type' => 'status', 'sort_order' => 2],
            ['field_key' => 'audit',               'field_label' => 'Audit',               'aggregation' => 'latest', 'input_type' => 'status', 'sort_order' => 3],
            ['field_key' => 'miscellaneous',       'field_label' => 'Miscellaneous',       'aggregation' => 'latest', 'input_type' => 'textarea', 'sort_order' => 4],
        ];

        foreach ($fields as $f) {
            $exists = KpiDefinition::where('user_id', $karuna->id)
                ->where('field_key', $f['field_key'])
                ->exists();

            if (! $exists) {
                KpiDefinition::create(array_merge($f, [
                    'user_id' => $karuna->id,
                    'group_name' => 'Daily Tasks',
                    'created_by' => $karuna->id,
                    'effective_from' => $weekStart,
                ]));
            }
        }
    }

    public function down(): void
    {
        $karuna = User::where('email', 'karuna@innovfix.in')->first();
        if ($karuna) {
            KpiDefinition::where('user_id', $karuna->id)->forceDelete();
        }
    }
};
