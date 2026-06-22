<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Fida's promotion to "Lead AI Engineer". This is a faithful clone of
        // the gen_ai_developer role — same permissions, same IC scope (see
        // Role::IC_SLUGS) and same KRA weights (config/kra_weights.php) — but
        // with its own display name so "Lead AI Engineer" surfaces everywhere a
        // role name is shown (attendance roster, HR dashboard, admin lists),
        // without disturbing the other gen_ai_developers. It's also selectable
        // in the Add-Member Role dropdown (Role::orderBy('name')).
        //
        // Permissions are cloned from gen_ai_developer's CURRENT rows so a
        // migrate-only run on the live DB yields the full set immediately
        // (PermissionSeeder re-affirms the mirror on a fresh install). Idempotent.
        if (! DB::table('roles')->where('slug', 'lead_ai_engineer')->exists()) {
            DB::table('roles')->insert([
                'name' => 'Lead AI Engineer',
                'slug' => 'lead_ai_engineer',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $src = DB::table('roles')->where('slug', 'gen_ai_developer')->first();
        $lead = DB::table('roles')->where('slug', 'lead_ai_engineer')->first();
        if ($src && $lead) {
            $srcPermissions = DB::table('permissions')->where('role_id', $src->id)->pluck('permission');
            $existing = DB::table('permissions')->where('role_id', $lead->id)->pluck('permission')->all();
            $now = now();
            $rows = [];
            foreach ($srcPermissions as $permission) {
                if (! in_array($permission, $existing, true)) {
                    $rows[] = [
                        'role_id' => $lead->id,
                        'permission' => $permission,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
            if ($rows) {
                DB::table('permissions')->insert($rows);
            }

            // Move Fida (user #41) onto the new role. Her meeting access is
            // preserved (she owns the gen-ai intern standup and attends the
            // CEO AI standup; the cloned meeting.access permission keeps the
            // feature on). Guarded so it only ever touches the gen_ai role.
            DB::table('users')
                ->where('id', 41)
                ->where('role_id', $src->id)
                ->update(['role_id' => $lead->id]);
        }
    }

    public function down(): void
    {
        $src = DB::table('roles')->where('slug', 'gen_ai_developer')->first();
        $lead = DB::table('roles')->where('slug', 'lead_ai_engineer')->first();
        if ($lead) {
            if ($src) {
                DB::table('users')
                    ->where('id', 41)
                    ->where('role_id', $lead->id)
                    ->update(['role_id' => $src->id]);
            }
            DB::table('permissions')->where('role_id', $lead->id)->delete();
            DB::table('roles')->where('id', $lead->id)->delete();
        }
    }
};
