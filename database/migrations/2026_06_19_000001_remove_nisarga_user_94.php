<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Permanently remove Nisarga C A (user #94).
     *
     * QA-intern probation account, accidentally created with role_id=1 (CEO)
     * and a typo'd email (nisagra@innovfix.in). Exists only in the live DB
     * (no source/config references). Footprint at audit time: a single
     * daily_signins row; 0 subordinates / meetings / tasks / leaves / JSON refs.
     *
     * Hard delete requested. Follows the delete-user precedent
     * (2026_03_10_000005_delete_duplicate_users): scrub loose, non-FK
     * references by hand, then delete (FK CASCADE/SET NULL handle the rest).
     */
    public function up(): void
    {
        $id = 94;

        // Safety guard: only proceed if #94 is still who we expect.
        $user = DB::table('users')->where('id', $id)->first();
        if (! $user || stripos((string) $user->name, 'nisarga') === false) {
            return; // already gone, or id reused — do nothing.
        }

        // 1. Null any manager pointers at #94 (secondary_manager_id has NO FK;
        //    reporting_manager_id is nullOnDelete but null it explicitly too).
        //    NOTE: `users` has timestamps disabled (no updated_at) — never set it.
        DB::table('users')->where('reporting_manager_id', $id)
            ->update(['reporting_manager_id' => null]);
        DB::table('users')->where('secondary_manager_id', $id)
            ->update(['secondary_manager_id' => null]);

        // 2. Scrub #94 from meetings.attendees JSON arrays (no FK on JSON).
        $meetings = DB::table('meetings')
            ->whereRaw('JSON_CONTAINS(COALESCE(attendees, JSON_ARRAY()), ?)', [(string) $id])
            ->get(['id', 'attendees']);
        foreach ($meetings as $m) {
            $att = json_decode($m->attendees ?? '[]', true) ?: [];
            $att = array_values(array_filter($att, fn ($v) => (int) $v !== $id));
            DB::table('meetings')->where('id', $m->id)
                ->update(['attendees' => json_encode($att)]);
        }

        // 3. Delete the user. FK CASCADE removes child rows (e.g. her 1
        //    daily_signins row); SET NULL FKs null their pointers. No RESTRICT
        //    child rows exist, so the delete is not blocked.
        DB::table('users')->where('id', $id)->delete();
    }

    public function down(): void
    {
        // Irreversible: a hard-deleted user cannot be restored without an
        // external backup. Intentional no-op so rollback batches don't fail.
    }
};
