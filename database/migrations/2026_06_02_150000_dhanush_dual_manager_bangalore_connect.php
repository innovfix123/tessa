<?php

use App\Models\KpiDefinition;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Dhanush (#13) becomes dual-managed:
 *   - Primary reporting manager -> Bala (#2): ratings + leave approvals.
 *   - Secondary (dotted-line)   -> Sneha Sunoj (#5): keeps her Daily Reports
 *     tab access + a leave-approval FYI (see config/leave_notify_cc.php).
 *
 * Daily report gains two "Bangalore Connect" conversion KPIs (mirroring the
 * retired Thedal ones) ALONGSIDE the existing "What did you work on today?"
 * box. Per-field manager visibility is config-driven
 * (config/daily_report_field_visibility.php): Bala sees the Bangalore Connect
 * fields, Sneha sees the work-summary field.
 */
return new class extends Migration
{
    private const DHANUSH = 13;
    private const BALA = 2;
    private const SNEHA = 5;

    private const BANGALORE_FIELDS = [
        ['field_key' => 'bangalore_registration_to_trial_pct', 'field_label' => 'Registration → Trial %', 'aggregation' => 'avg', 'sort_order' => 0],
        ['field_key' => 'bangalore_trial_to_premium_pct_d7',   'field_label' => 'Trial → Premium % (D-7)', 'aggregation' => 'avg', 'sort_order' => 1],
    ];

    public function up(): void
    {
        $dhanush = User::find(self::DHANUSH);
        if (! $dhanush) {
            return;
        }

        $weekStart = Carbon::now('Asia/Kolkata')->startOfWeek(Carbon::MONDAY)->format('Y-m-d');

        DB::transaction(function () use ($dhanush, $weekStart) {
            foreach (self::BANGALORE_FIELDS as $f) {
                $exists = KpiDefinition::where('user_id', self::DHANUSH)
                    ->where('field_key', $f['field_key'])
                    ->whereNull('deleted_at')
                    ->exists();
                if ($exists) {
                    continue;
                }
                KpiDefinition::create(array_merge($f, [
                    'user_id' => self::DHANUSH,
                    'group_name' => 'Bangalore Connect',
                    'input_type' => 'text',
                    'auto_sync' => false,
                    'created_by' => self::DHANUSH,
                    'effective_from' => $weekStart,
                ]));
            }

            // Back to Bala as primary; Sneha becomes the dotted-line manager.
            $dhanush->update([
                'reporting_manager_id' => self::BALA,
                'secondary_manager_id' => self::SNEHA,
            ]);
        });
    }

    public function down(): void
    {
        $dhanush = User::find(self::DHANUSH);
        if (! $dhanush) {
            return;
        }

        DB::transaction(function () use ($dhanush) {
            KpiDefinition::where('user_id', self::DHANUSH)
                ->whereIn('field_key', array_column(self::BANGALORE_FIELDS, 'field_key'))
                ->forceDelete();

            // Revert to the prior state (under Sneha, no secondary).
            $dhanush->update([
                'reporting_manager_id' => self::SNEHA,
                'secondary_manager_id' => null,
            ]);
        });
    }
};
