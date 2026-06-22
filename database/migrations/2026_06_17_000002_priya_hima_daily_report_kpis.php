<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Priya (Priyadharshini, priya@innovfix.in, user #91, content_moderator_qa, reports to
 * Ranjini #27) has moved to content moderation on the Hima project. Her daily-report
 * metrics are changed from the QA pair (bugs_reported / bugs_retested, set up by
 * 2026_06_15_000002_setup_priya_content_moderator_qa) to three Hima fields, and she is
 * assigned to the Hima project (she had no project_assignments row).
 *
 * New daily-report fields (group "Content Moderation"):
 *   1. No. of Users      -> input_type 'text', aggregation 'sum', MANDATORY (optional=0)
 *   2. No. of Creators   -> input_type 'text', aggregation 'sum', MANDATORY (optional=0)
 *   3. Description       -> input_type 'text_multiline', no rollup, OPTIONAL (optional=1)
 *
 * Notes:
 *  - "mandatory vs optional" is the kpi_definitions.optional flag, enforced only at sign-off:
 *    SignoffStatusService::getDefinedFieldsForUser() considers only optional=0 fields, so the
 *    two counts must be filled before she can sign off and the Description never blocks.
 *  - "Description box" uses input_type 'text_multiline' (web portal renders this as an inline
 *    <textarea rows=3>); 'textarea' is a different modal/upload widget. text_multiline is also
 *    exempt from the [₹%,\s] numeric strip in DailyReportController::store, so prose survives.
 *  - `optional` is not in KpiDefinition::$fillable, but a raw query-builder insert sets it
 *    directly (fillable only guards Eloquent mass-assignment).
 *  - Priya has zero daily_reports rows, so the old defs are simply replaced (effective_from
 *    NULL = visible immediately). created_by 27 (Ranjini) mirrors her existing defs.
 *  - project_assignments has UNIQUE(user_id, project_id); insertOrIgnore is idempotent.
 *    user_id is a signed INT FK to users.id.
 */
return new class extends Migration
{
    private const PRIYA = 91;
    private const HIMA = 1;        // project
    private const RANJINI = 27;    // her manager / created_by
    private const GROUP = 'Content Moderation';

    /** @return array<int,array<string,mixed>> */
    private function newFields(): array
    {
        return [
            ['field_key' => 'no_of_users',    'field_label' => 'No. of Users',    'aggregation' => 'sum', 'input_type' => 'text',           'optional' => false, 'sort_order' => 0],
            ['field_key' => 'no_of_creators', 'field_label' => 'No. of Creators', 'aggregation' => 'sum', 'input_type' => 'text',           'optional' => false, 'sort_order' => 1],
            ['field_key' => 'description',    'field_label' => 'Description',     'aggregation' => null,  'input_type' => 'text_multiline', 'optional' => true,  'sort_order' => 2],
        ];
    }

    public function up(): void
    {
        $now = now();

        // 1. Drop the old QA metrics and add the three Hima content-moderation fields.
        DB::table('kpi_definitions')
            ->where('user_id', self::PRIYA)
            ->whereIn('field_key', ['bugs_reported', 'bugs_retested'])
            ->delete();

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

        // 2. Assign her to the Hima project (idempotent).
        DB::table('project_assignments')->insertOrIgnore([
            'user_id'    => self::PRIYA,
            'project_id' => self::HIMA,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $now = now();

        // Remove the three new fields and restore the original QA pair.
        DB::table('kpi_definitions')
            ->where('user_id', self::PRIYA)
            ->whereIn('field_key', array_column($this->newFields(), 'field_key'))
            ->delete();

        $original = [
            ['field_key' => 'bugs_reported', 'field_label' => 'Bugs Reported', 'sort_order' => 0],
            ['field_key' => 'bugs_retested', 'field_label' => 'Bugs Retested', 'sort_order' => 1],
        ];
        foreach ($original as $field) {
            $exists = DB::table('kpi_definitions')
                ->where('user_id', self::PRIYA)
                ->where('field_key', $field['field_key'])
                ->whereNull('deleted_at')
                ->exists();
            if (! $exists) {
                DB::table('kpi_definitions')->insert(array_merge($field, [
                    'user_id'     => self::PRIYA,
                    'group_name'  => 'QA Testing',
                    'aggregation' => 'sum',
                    'input_type'  => 'text',
                    'optional'    => false,
                    'created_by'  => self::RANJINI,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ]));
            }
        }

        DB::table('project_assignments')
            ->where('user_id', self::PRIYA)
            ->where('project_id', self::HIMA)
            ->delete();
    }
};
