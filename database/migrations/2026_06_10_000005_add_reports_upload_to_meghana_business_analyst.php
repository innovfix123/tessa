<?php

use App\Models\KpiDefinition;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;

/*
 * Add one OPTIONAL "Reports" upload row to Meghana's (meghana@innovfix.in)
 * Daily Report, inside her existing "Business Analyst" group. She can upload any
 * file (pptx / pdf / word / etc.) — it's optional and the only optional row in
 * that group, so it never blocks her sign-off.
 *
 * Additive only: nothing is retired. `upload` renders the "Upload ▾" panel and
 * stores via /api/creative-uploads. sort_order 8 (global max + 1) keeps it last
 * inside the Business Analyst block — getFieldsForUser orders by group_name then
 * sort_order, and "Business Analyst" sorts before "HR". `upload_accept` null =
 * any file type. `effective_from` = this week's Monday so it appears from this
 * week forward and isn't back-filled into past weeks.
 */
return new class extends Migration
{
    public function up(): void
    {
        $meghana = User::where('email', 'meghana@innovfix.in')->first();
        if (! $meghana) {
            return;
        }

        $weekStartDate = Carbon::now('Asia/Kolkata')->startOfWeek(Carbon::MONDAY)->format('Y-m-d');

        $exists = KpiDefinition::withTrashed()
            ->where('user_id', $meghana->id)
            ->where('field_key', 'reports')
            ->exists();

        if (! $exists) {
            KpiDefinition::create([
                'user_id' => $meghana->id,
                'group_name' => 'Business Analyst',
                'field_key' => 'reports',
                'field_label' => 'Reports',
                'aggregation' => null,
                'input_type' => 'upload',
                'upload_accept' => null, // any file type
                'upload_max_mb' => 25,
                'sort_order' => 8,
                'created_by' => $meghana->reporting_manager_id ?? $meghana->id,
                'effective_from' => $weekStartDate,
            ]);
        }

        // `optional` is not mass-assignable on KpiDefinition, so set it
        // explicitly (idempotent), mirroring the Bhoomika/Ranjini migrations.
        KpiDefinition::where('user_id', $meghana->id)
            ->where('field_key', 'reports')
            ->update(['optional' => true]);
    }

    public function down(): void
    {
        $meghana = User::where('email', 'meghana@innovfix.in')->first();
        if (! $meghana) {
            return;
        }

        KpiDefinition::withTrashed()
            ->where('user_id', $meghana->id)
            ->where('field_key', 'reports')
            ->forceDelete();
    }
};
