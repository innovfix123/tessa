<?php

use App\Models\KpiDefinition;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Sumit (#63) moved under Perumal (#37) as a Full Stack Developer Intern.
 * Make his daily report match Perumal's: retire his old "Business Analyst"
 * KPI fields (kept visible for past weeks) and clone Perumal's "Daily Tasks"
 * fields, effective this week. Also flip his designation.
 */
return new class extends Migration
{
    // Snapshot of Perumal's "Daily Tasks" daily-report fields (as of 2026-06-02).
    private const PERUMAL_FIELDS = [
        ['field_key' => 'tasks_completed', 'field_label' => 'Tasks Completed',     'input_type' => 'text',     'aggregation' => 'sum',    'sort_order' => 0],
        ['field_key' => 'tasks_pending',   'field_label' => 'Tasks Pending',       'input_type' => 'text',     'aggregation' => 'latest', 'sort_order' => 1],
        ['field_key' => 'bugs_backlogs',   'field_label' => 'Bugs / Backlogs',     'input_type' => 'text',     'aggregation' => 'sum',    'sort_order' => 2],
        ['field_key' => 'bugs_fixed',      'field_label' => 'Bugs Fixed',          'input_type' => 'text',     'aggregation' => 'sum',    'sort_order' => 3],
        ['field_key' => 'blockers',        'field_label' => 'Blockers (Optional)', 'input_type' => 'textarea', 'aggregation' => 'latest', 'sort_order' => 4],
    ];

    public function up(): void
    {
        $sumit = User::where('email', 'sumit@innovfix.in')->first();
        if (! $sumit) {
            return;
        }

        $weekStart = Carbon::now('Asia/Kolkata')->startOfWeek(Carbon::MONDAY);
        $weekStartDate = $weekStart->format('Y-m-d');
        $retireAt = $weekStart->copy()->subDay()->endOfDay();

        DB::transaction(function () use ($sumit, $weekStartDate, $retireAt) {
            // Retire his old daily-report fields (the "Business Analyst" set) at the
            // week boundary: kept for past weeks, gone from this week onward.
            KpiDefinition::where('user_id', $sumit->id)
                ->where('group_name', '!=', 'Daily Tasks')
                ->whereNotIn('field_key', ['_group_init', '_person_init', '_placeholder'])
                ->whereNull('deleted_at')
                ->update(['deleted_at' => $retireAt]);

            // Clone Perumal's "Daily Tasks" fields, effective this week.
            foreach (self::PERUMAL_FIELDS as $f) {
                $exists = KpiDefinition::where('user_id', $sumit->id)
                    ->where('group_name', 'Daily Tasks')
                    ->where('field_key', $f['field_key'])
                    ->whereNull('deleted_at')
                    ->exists();
                if ($exists) {
                    continue;
                }

                KpiDefinition::create(array_merge($f, [
                    'user_id' => $sumit->id,
                    'group_name' => 'Daily Tasks',
                    'auto_sync' => false,
                    'created_by' => $sumit->id,
                    'effective_from' => $weekStartDate,
                ]));
            }

            // Title: Data Analyst Intern -> Full Stack Developer Intern.
            $sumit->update(['designation' => 'Full Stack Developer Intern']);
        });
    }

    public function down(): void
    {
        $sumit = User::where('email', 'sumit@innovfix.in')->first();
        if (! $sumit) {
            return;
        }

        DB::transaction(function () use ($sumit) {
            // Drop the cloned Daily Tasks fields…
            KpiDefinition::where('user_id', $sumit->id)
                ->where('group_name', 'Daily Tasks')
                ->forceDelete();

            // …and bring back his Business Analyst fields.
            KpiDefinition::withTrashed()
                ->where('user_id', $sumit->id)
                ->where('group_name', 'Business Analyst')
                ->update(['deleted_at' => null]);

            $sumit->update(['designation' => 'Data Analyst Intern']);
        });
    }
};
