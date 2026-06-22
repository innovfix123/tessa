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

        KpiDefinition::where('user_id', $ranjini->id)
            ->whereIn('group_name', ['Hima', 'Unman'])
            ->update(['optional' => true]);

        $exists = KpiDefinition::where('user_id', $ranjini->id)
            ->where('field_key', 'worked_on_today')
            ->exists();

        if (! $exists) {
            $kpi = KpiDefinition::create([
                'user_id' => $ranjini->id,
                'group_name' => 'Daily Summary',
                'field_key' => 'worked_on_today',
                'field_label' => 'What did you work on today?',
                'aggregation' => 'latest',
                'input_type' => 'textarea',
                'sort_order' => 0,
                'created_by' => $ranjini->id,
            ]);
            $kpi->optional = true;
            $kpi->save();
        }
    }

    public function down(): void
    {
        $ranjini = User::where('email', 'ranjini@innovfix.in')->first();
        if (! $ranjini) {
            return;
        }

        KpiDefinition::where('user_id', $ranjini->id)
            ->whereIn('group_name', ['Hima', 'Unman'])
            ->update(['optional' => false]);

        KpiDefinition::where('user_id', $ranjini->id)
            ->where('field_key', 'worked_on_today')
            ->delete();
    }
};
