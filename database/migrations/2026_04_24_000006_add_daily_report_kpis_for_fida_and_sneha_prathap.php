<?php

use App\Models\KpiDefinition;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $users = User::whereIn('email', ['fida@innovfix.in', 'snehaintern@innovfix.in'])->get();

        $fields = [
            ['key' => 'what_did_you_work_on_today', 'label' => 'What did you work on today?', 'aggregation' => 'latest', 'input_type' => 'textarea'],
            ['key' => 'total_bugs', 'label' => 'Total bugs', 'aggregation' => 'sum', 'input_type' => 'text'],
            ['key' => 'bugs_fixed', 'label' => 'Bugs fixed', 'aggregation' => 'sum', 'input_type' => 'text'],
            ['key' => 'blockers', 'label' => 'Blockers', 'aggregation' => 'latest', 'input_type' => 'textarea'],
        ];

        foreach ($users as $user) {
            $sortOrder = 0;
            foreach ($fields as $field) {
                $exists = KpiDefinition::where('user_id', $user->id)
                    ->where('field_key', $field['key'])
                    ->exists();

                if (! $exists) {
                    KpiDefinition::create([
                        'user_id' => $user->id,
                        'group_name' => 'Development',
                        'field_key' => $field['key'],
                        'field_label' => $field['label'],
                        'aggregation' => $field['aggregation'],
                        'input_type' => $field['input_type'],
                        'sort_order' => $sortOrder++,
                        'created_by' => $user->id,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        $users = User::whereIn('email', ['fida@innovfix.in', 'snehaintern@innovfix.in'])->get();
        foreach ($users as $user) {
            KpiDefinition::where('user_id', $user->id)->delete();
        }
    }
};
