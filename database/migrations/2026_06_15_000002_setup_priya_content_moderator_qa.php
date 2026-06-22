<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Finishes Priya's (priya@innovfix.in, user #91) Content Moderator & QA setup so
 * she gets a Daily Reports workflow like the other people under Ranjini (#27).
 *
 *   1. Make the bare `content_moderator_qa` role a functional clone of
 *      `qa_analyst` (Laxmi/Iksha's role) by mirroring its permission rows —
 *      dynamic + additive, the same pattern as add_lead_ai_engineer_role /
 *      add_hr_operations_role. This is what lights up her Dashboard + Daily
 *      Reports + KPI tabs.
 *   2. Clone the "QA Testing" KPI fields (bugs_reported, bugs_retested) for Priya
 *      so she can file daily reports AND show up in Ranjini's Daily Reports view
 *      (the manager tab only lists reports that have KPI definitions).
 *   3. Reset her password to the standard default (12345678) since the existing
 *      hash is unknown, and fill her designation.
 */
return new class extends Migration
{
    private string $email = 'priya@innovfix.in';

    /** @return array<int,array<string,mixed>> */
    private function kpiFields(): array
    {
        return [
            ['group_name' => 'QA Testing', 'field_key' => 'bugs_reported', 'field_label' => 'Bugs Reported', 'aggregation' => 'sum', 'input_type' => 'text', 'sort_order' => 0],
            ['group_name' => 'QA Testing', 'field_key' => 'bugs_retested', 'field_label' => 'Bugs Retested', 'aggregation' => 'sum', 'input_type' => 'text', 'sort_order' => 1],
        ];
    }

    public function up(): void
    {
        $now = now();

        // 1. Mirror qa_analyst's permission rows onto content_moderator_qa (additive).
        $src = DB::table('roles')->where('slug', 'qa_analyst')->first();
        $target = DB::table('roles')->where('slug', 'content_moderator_qa')->first();
        if ($src && $target) {
            $existing = DB::table('permissions')->where('role_id', $target->id)->pluck('permission')->all();
            $rows = [];
            foreach (DB::table('permissions')->where('role_id', $src->id)->pluck('permission') as $permission) {
                if (! in_array($permission, $existing, true)) {
                    $rows[] = [
                        'role_id' => $target->id,
                        'permission' => $permission,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
            if ($rows) {
                DB::table('permissions')->insert($rows);
            }
        }

        $priya = DB::table('users')->where('email', $this->email)->first();
        if (! $priya) {
            return;
        }

        // 2. Clone QA Testing KPI definitions for Priya (idempotent per field_key).
        $createdBy = $priya->reporting_manager_id ?: $priya->id;
        foreach ($this->kpiFields() as $field) {
            $exists = DB::table('kpi_definitions')
                ->where('user_id', $priya->id)
                ->where('field_key', $field['field_key'])
                ->exists();
            if (! $exists) {
                DB::table('kpi_definitions')->insert(array_merge($field, [
                    'user_id' => $priya->id,
                    'created_by' => $createdBy,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]));
            }
        }

        // 3. Reset password to the standard default + tidy the row.
        // NOTE: the users table has no updated_at column (timestamps disabled).
        $update = ['password_hash' => password_hash('12345678', PASSWORD_BCRYPT)];
        if (empty($priya->designation)) {
            $update['designation'] = 'Content Moderator & QA';
        }
        if (empty($priya->created_at)) {
            $update['created_at'] = $now;
        }
        DB::table('users')->where('id', $priya->id)->update($update);
    }

    public function down(): void
    {
        // Remove the cloned KPI defs and the role's permission rows (the role was
        // bare before this migration). Password/designation are left as-is.
        $priya = DB::table('users')->where('email', $this->email)->first();
        if ($priya) {
            DB::table('kpi_definitions')
                ->where('user_id', $priya->id)
                ->whereIn('field_key', array_column($this->kpiFields(), 'field_key'))
                ->delete();
        }

        $target = DB::table('roles')->where('slug', 'content_moderator_qa')->first();
        if ($target) {
            DB::table('permissions')->where('role_id', $target->id)->delete();
        }
    }
};
