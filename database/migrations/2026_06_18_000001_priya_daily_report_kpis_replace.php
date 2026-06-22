<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Replace Priya's (Priyadharshini, priya@innovfix.in, user #91, content_moderator_qa,
 * reports to Ranjini #27) daily-report metrics, and turn her Daily Reports tab on
 * (config/daily_reports_access.php user_ids += 91, separate change).
 *
 * The prior setup (2026_06_17_000002_priya_hima_daily_report_kpis) created three fields
 * (No. of Users / No. of Creators / Description) BEFORE her tab was ever switched on, so she
 * never used them and their daily_reports values are empty placeholders. They are replaced with
 * EXACTLY two mandatory count metrics under group "Content Moderation":
 *   1. Number of Male Users      -> input_type 'text', aggregation 'sum', MANDATORY (optional=0)
 *   2. Number of Female Creators -> input_type 'text', aggregation 'sum', MANDATORY (optional=0)
 *
 * Notes:
 *  - "mandatory" is kpi_definitions.optional=0, enforced at sign-off
 *    (SignoffStatusService::getDefinedFieldsForUser considers only optional=0 fields), so both
 *    counts must be filled before she can sign off.
 *  - `optional` is not in KpiDefinition::$fillable, but a raw query-builder insert sets it
 *    directly (fillable only guards Eloquent mass-assignment).
 *  - effective_from NULL = visible immediately. created_by 27 (Ranjini) mirrors her existing defs.
 *  - Query-builder ->delete() is a hard delete (bypasses SoftDeletes), matching the source
 *    migration; the retired defs and their empty daily_reports rows are removed outright.
 */
return new class extends Migration
{
    private const PRIYA = 91;
    private const RANJINI = 27;    // her manager / created_by
    private const GROUP = 'Content Moderation';

    /** Field_keys retired by this migration (from the 2026_06_17 setup). */
    private const OLD_KEYS = ['no_of_users', 'no_of_creators', 'description'];

    /** @return array<int,array<string,mixed>> */
    private function newFields(): array
    {
        return [
            ['field_key' => 'number_of_male_users',      'field_label' => 'Number of Male Users',      'aggregation' => 'sum', 'input_type' => 'text', 'optional' => false, 'sort_order' => 0],
            ['field_key' => 'number_of_female_creators', 'field_label' => 'Number of Female Creators', 'aggregation' => 'sum', 'input_type' => 'text', 'optional' => false, 'sort_order' => 1],
        ];
    }

    public function up(): void
    {
        $now = now();

        // 1. Drop the three prior fields and their (empty) daily_reports values.
        DB::table('kpi_definitions')
            ->where('user_id', self::PRIYA)
            ->whereIn('field_key', self::OLD_KEYS)
            ->delete();
        DB::table('daily_reports')
            ->where('user_id', self::PRIYA)
            ->whereIn('field_key', self::OLD_KEYS)
            ->delete();

        // 2. Insert the two new mandatory count metrics (idempotent guard).
        foreach ($this->newFields() as $field) {
            $exists = DB::table('kpi_definitions')
                ->where('user_id', self::PRIYA)
                ->where('field_key', $field['field_key'])
                ->whereNull('deleted_at')
                ->exists();
            if (! $exists) {
                DB::table('kpi_definitions')->insert(array_merge($field, [
                    'user_id'        => self::PRIYA,
                    'group_name'     => self::GROUP,
                    'effective_from' => null,
                    'created_by'     => self::RANJINI,
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ]));
            }
        }
    }

    public function down(): void
    {
        $now = now();

        // Remove the two new fields and restore the prior three (no values restored).
        DB::table('kpi_definitions')
            ->where('user_id', self::PRIYA)
            ->whereIn('field_key', array_column($this->newFields(), 'field_key'))
            ->delete();

        $original = [
            ['field_key' => 'no_of_users',    'field_label' => 'No. of Users',    'aggregation' => 'sum', 'input_type' => 'text',           'optional' => false, 'sort_order' => 0],
            ['field_key' => 'no_of_creators', 'field_label' => 'No. of Creators', 'aggregation' => 'sum', 'input_type' => 'text',           'optional' => false, 'sort_order' => 1],
            ['field_key' => 'description',    'field_label' => 'Description',     'aggregation' => null,  'input_type' => 'text_multiline', 'optional' => true,  'sort_order' => 2],
        ];
        foreach ($original as $field) {
            $exists = DB::table('kpi_definitions')
                ->where('user_id', self::PRIYA)
                ->where('field_key', $field['field_key'])
                ->whereNull('deleted_at')
                ->exists();
            if (! $exists) {
                DB::table('kpi_definitions')->insert(array_merge($field, [
                    'user_id'        => self::PRIYA,
                    'group_name'     => self::GROUP,
                    'effective_from' => null,
                    'created_by'     => self::RANJINI,
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ]));
            }
        }
    }
};
