<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $meeting = DB::table('meetings')->where('meeting_key', 'ceo-cmo')->first();
        if ($meeting) {
            $meetingKey = $meeting->meeting_key;
            DB::table('discussion_points')->where('meeting_id', $meetingKey)->delete();
            DB::table('action_items')->where('meeting_id', $meetingKey)->delete();
            DB::table('meeting_notes')->where('meeting_id', $meetingKey)->delete();
            DB::table('agenda_sections')->where('meeting_id', $meetingKey)->delete();
            DB::table('meetings')->where('meeting_key', 'ceo-cmo')->delete();
        }
    }

    public function down(): void
    {
        // Cannot restore deleted meeting without seeder data
    }
};
