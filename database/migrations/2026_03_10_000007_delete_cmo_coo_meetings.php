<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const MEETING_KEYS = ['cmo-sudar', 'coo-cmo', 'cmo-thedal'];

    public function up(): void
    {
        foreach (self::MEETING_KEYS as $key) {
            $meeting = DB::table('meetings')->where('meeting_key', $key)->first();
            if ($meeting) {
                DB::table('discussion_points')->where('meeting_id', $key)->delete();
                DB::table('action_items')->where('meeting_id', $key)->delete();
                DB::table('meeting_notes')->where('meeting_id', $key)->delete();
                DB::table('agenda_sections')->where('meeting_id', $key)->delete();
                DB::table('meetings')->where('meeting_key', $key)->delete();
            }
        }
    }

    public function down(): void
    {
        // Cannot restore deleted meetings without seeder data
    }
};
