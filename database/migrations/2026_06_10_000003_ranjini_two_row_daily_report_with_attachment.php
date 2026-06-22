<?php

use App\Models\KpiDefinition;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Simplify Ranjini's (ranjini@innovfix.in) own Daily Report to match the
 * AI-intern layout Bhoomika (#60) has — just two free-text boxes,
 * "What did you work on today?" (required) and "Blockers" (optional) — plus one
 * extra row Ranjini gets: an OPTIONAL document attachment ("Supporting
 * document", an `upload` field).
 *
 * Her current rows (QA Testing counters, Hima/Unman groups, the old
 * `worked_on_today` summary) are retired at the week boundary the same way the
 * Dhanush migration does (2026_06_02_130000): they stay visible for past weeks
 * (history preserved) and disappear from this week onward, while the three new
 * rows become effective this week. The two textarea rows render as the "Add ▾"
 * side-panel (entries persist via /api/creative-uploads); the upload row renders
 * the "Upload ▾" panel. `optional` rows never gate sign-off, so only the work
 * write-up is required — Blockers and the attachment are not.
 */
return new class extends Migration
{
    private const NEW_KEYS = ['what_did_you_work_on_today', 'blockers', 'supporting_document'];

    public function up(): void
    {
        $ranjini = User::where('email', 'ranjini@innovfix.in')->first();
        if (! $ranjini) {
            return;
        }

        $weekStart = Carbon::now('Asia/Kolkata')->startOfWeek(Carbon::MONDAY);
        $weekStartDate = $weekStart->format('Y-m-d');
        // Retire one day before this week's Monday so visibleForWeek hides the
        // old fields from this week forward but keeps them for prior weeks.
        $retireAt = $weekStart->copy()->subDay()->endOfDay();

        // sort_order, label, type and the optional flag mirror Bhoomika's setup;
        // the third (upload) row is the only addition.
        $fields = [
            ['key' => 'what_did_you_work_on_today', 'label' => 'What did you work on today?', 'aggregation' => 'latest', 'input_type' => 'textarea', 'optional' => false, 'upload_accept' => null, 'upload_max_mb' => null],
            ['key' => 'blockers', 'label' => 'Blockers', 'aggregation' => 'latest', 'input_type' => 'textarea', 'optional' => true, 'upload_accept' => null, 'upload_max_mb' => null],
            ['key' => 'supporting_document', 'label' => 'Supporting document', 'aggregation' => null, 'input_type' => 'upload', 'optional' => true, 'upload_accept' => 'pdf,doc,docx,xls,xlsx,jpg,jpeg,png,webp', 'upload_max_mb' => 25],
        ];

        DB::transaction(function () use ($ranjini, $weekStartDate, $retireAt, $fields) {
            // Retire every current row except the three we're about to (re)create
            // — the whereNotIn keeps this idempotent if the migration re-runs.
            KpiDefinition::where('user_id', $ranjini->id)
                ->whereNotIn('field_key', self::NEW_KEYS)
                ->whereNull('deleted_at')
                ->update(['deleted_at' => $retireAt]);

            $sortOrder = 0;
            foreach ($fields as $field) {
                $exists = KpiDefinition::withTrashed()
                    ->where('user_id', $ranjini->id)
                    ->where('field_key', $field['key'])
                    ->exists();

                if (! $exists) {
                    KpiDefinition::create([
                        'user_id' => $ranjini->id,
                        'group_name' => 'Daily Update',
                        'field_key' => $field['key'],
                        'field_label' => $field['label'],
                        'aggregation' => $field['aggregation'],
                        'input_type' => $field['input_type'],
                        'upload_accept' => $field['upload_accept'],
                        'upload_max_mb' => $field['upload_max_mb'],
                        'sort_order' => $sortOrder,
                        'created_by' => $ranjini->reporting_manager_id ?? $ranjini->id,
                        'effective_from' => $weekStartDate,
                    ]);
                }
                $sortOrder++;

                // `optional` is not mass-assignable on KpiDefinition, so set it
                // explicitly (idempotent), mirroring the Bhoomika migration.
                KpiDefinition::where('user_id', $ranjini->id)
                    ->where('field_key', $field['key'])
                    ->update(['optional' => $field['optional']]);
            }
        });
    }

    public function down(): void
    {
        $ranjini = User::where('email', 'ranjini@innovfix.in')->first();
        if (! $ranjini) {
            return;
        }

        // Match the retire boundary up() used (day before this week's Monday).
        // Restoring by this date leaves rows trashed earlier (Ranjini already had
        // some, e.g. retired on 2026-04-19) untouched.
        $retireDate = Carbon::now('Asia/Kolkata')->startOfWeek(Carbon::MONDAY)->subDay()->toDateString();

        DB::transaction(function () use ($ranjini, $retireDate) {
            // Drop the three new rows…
            KpiDefinition::withTrashed()
                ->where('user_id', $ranjini->id)
                ->whereIn('field_key', self::NEW_KEYS)
                ->forceDelete();

            // …and bring back only the rows this migration retired (trashed at
            // the boundary date above), not ones soft-deleted in earlier weeks.
            KpiDefinition::withTrashed()
                ->where('user_id', $ranjini->id)
                ->whereNotIn('field_key', self::NEW_KEYS)
                ->whereDate('deleted_at', $retireDate)
                ->update(['deleted_at' => null]);
        });
    }
};
