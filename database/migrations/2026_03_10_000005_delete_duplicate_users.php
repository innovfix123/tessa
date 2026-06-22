<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const DUPLICATE_TO_PRIMARY = [
        29 => 1,  // JP
        14 => 2,  // Bala
        15 => 3,  // Nandha
        30 => 4,  // Ayush
        31 => 5,  // Sneha Sunoj
    ];

    /**
     * Remap all references from duplicate user IDs to primary IDs, then delete duplicates.
     */
    public function up(): void
    {
        $duplicateIds = array_keys(self::DUPLICATE_TO_PRIMARY);

        // 1. Remap meeting attendees (JSON column)
        $meetings = DB::table('meetings')->get(['id', 'meeting_key', 'attendees']);
        foreach ($meetings as $meeting) {
            $attendees = json_decode($meeting->attendees ?? '[]', true);
            if (!is_array($attendees)) {
                continue;
            }
            $changed = false;
            $newAttendees = [];
            foreach ($attendees as $id) {
                $id = (int) $id;
                if (isset(self::DUPLICATE_TO_PRIMARY[$id])) {
                    $newId = self::DUPLICATE_TO_PRIMARY[$id];
                    if (!in_array($newId, $newAttendees, true)) {
                        $newAttendees[] = $newId;
                    }
                    $changed = true;
                } else {
                    $newAttendees[] = $id;
                }
            }
            if ($changed) {
                DB::table('meetings')
                    ->where('id', $meeting->id)
                    ->update(['attendees' => json_encode(array_values($newAttendees))]);
            }
        }

        // 2. Update agenda_templates.created_by
        DB::table('agenda_templates')
            ->whereIn('created_by', $duplicateIds)
            ->update(['created_by' => 4]);

        // 3. Nullify reporting_manager_id pointing to duplicates
        DB::table('users')
            ->whereIn('reporting_manager_id', $duplicateIds)
            ->update(['reporting_manager_id' => null]);

        // 4. Delete duplicate users
        DB::table('users')->whereIn('id', $duplicateIds)->delete();
    }

    public function down(): void
    {
        // Cannot restore deleted users without external backup
    }
};
