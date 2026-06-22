<?php

use App\Models\KpiDefinition;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;

/**
 * Daily-report KPI definitions for the two new AI interns, Bhuvan Prasad
 * (bhuvan@innovfix.in) and Bhoomika (bhoomika@innovfix.in).
 *
 * Same as the Gen AI Developer setup (see the Fida/Sneha Prathap migration)
 * but WITHOUT the bug counters — interns only get the two free-text boxes:
 * "What did you work on today?" (required) and "Blockers" (optional), matching
 * what a Gen AI Developer sees today. Reporting/leave/rating routing is driven
 * by users.reporting_manager_id (= Fida, 41) and needs no change here.
 */
return new class extends Migration
{
    private const EMAILS = ['bhuvan@innovfix.in', 'bhoomika@innovfix.in'];

    public function up(): void
    {
        $users = User::whereIn('email', self::EMAILS)->get();

        // sort_order is assigned sequentially (0,1) the same way the Gen AI
        // Developer migration does — the two bug fields are simply omitted.
        $fields = [
            ['key' => 'what_did_you_work_on_today', 'label' => 'What did you work on today?', 'aggregation' => 'latest', 'input_type' => 'textarea', 'optional' => false],
            ['key' => 'blockers', 'label' => 'Blockers', 'aggregation' => 'latest', 'input_type' => 'textarea', 'optional' => true],
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
                        'sort_order' => $sortOrder,
                        'created_by' => $user->reporting_manager_id ?? $user->id,
                    ]);
                }
                $sortOrder++;

                // `optional` is not mass-assignable on KpiDefinition, so set it
                // explicitly (idempotent) to mirror the current Gen AI Dev state.
                KpiDefinition::where('user_id', $user->id)
                    ->where('field_key', $field['key'])
                    ->update(['optional' => $field['optional']]);
            }
        }
    }

    public function down(): void
    {
        $users = User::whereIn('email', self::EMAILS)->get();
        foreach ($users as $user) {
            KpiDefinition::where('user_id', $user->id)
                ->whereIn('field_key', ['what_did_you_work_on_today', 'blockers'])
                ->delete();
        }
    }
};
