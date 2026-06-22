<?php

use App\Models\KpiDefinition;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Replace Dhanush's daily report KPIs (two "Thedal" conversion-% numeric fields)
 * with a single free-text box: "What did you work on today?".
 *
 * The old fields are retired at the week boundary — they stay visible for past
 * weeks (history preserved) and disappear from this week onward, while the new
 * text box becomes effective this week. text_multiline renders an inline
 * <textarea> whose value is stored verbatim (exempt from the numeric strip in
 * DailyReportController::store).
 */
return new class extends Migration
{
    private const OLD_KEYS = ['registration_to_trial_pct', 'trial_to_premium_pct_d7'];

    private const NEW_KEY = 'daily_work_summary';

    public function up(): void
    {
        $dhanush = User::where('email', 'dhanush@innovfix.in')->first();
        if (! $dhanush) {
            return;
        }

        $weekStart = Carbon::now('Asia/Kolkata')->startOfWeek(Carbon::MONDAY);
        $weekStartDate = $weekStart->format('Y-m-d');
        // Retire one day before this week's Monday so visibleForWeek hides the
        // old fields from this week forward but keeps them for prior weeks.
        $retireAt = $weekStart->copy()->subDay()->endOfDay();

        DB::transaction(function () use ($dhanush, $weekStartDate, $retireAt) {
            KpiDefinition::where('user_id', $dhanush->id)
                ->whereIn('field_key', self::OLD_KEYS)
                ->whereNull('deleted_at')
                ->update(['deleted_at' => $retireAt]);

            $exists = KpiDefinition::withTrashed()
                ->where('user_id', $dhanush->id)
                ->where('field_key', self::NEW_KEY)
                ->exists();

            if (! $exists) {
                KpiDefinition::create([
                    'user_id' => $dhanush->id,
                    'group_name' => 'Daily Update',
                    'field_key' => self::NEW_KEY,
                    'field_label' => 'What did you work on today?',
                    'aggregation' => null,
                    'input_type' => 'text_multiline',
                    'sort_order' => 0,
                    'created_by' => $dhanush->id,
                    'effective_from' => $weekStartDate,
                ]);
            }
        });
    }

    public function down(): void
    {
        $dhanush = User::where('email', 'dhanush@innovfix.in')->first();
        if (! $dhanush) {
            return;
        }

        DB::transaction(function () use ($dhanush) {
            // Drop the single text box…
            KpiDefinition::withTrashed()
                ->where('user_id', $dhanush->id)
                ->where('field_key', self::NEW_KEY)
                ->forceDelete();

            // …and bring the retired numeric KPIs back.
            KpiDefinition::withTrashed()
                ->where('user_id', $dhanush->id)
                ->whereIn('field_key', self::OLD_KEYS)
                ->update(['deleted_at' => null]);
        });
    }
};
