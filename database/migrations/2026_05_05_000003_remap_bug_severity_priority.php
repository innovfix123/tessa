<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // New severity vocabulary: high / medium / low (drop "critical")
        DB::table('bugs')->where('severity', 'critical')->update(['severity' => 'high']);

        // New priority vocabulary: blocker / critical / major / minor
        // Mapping (preserves relative ordering):
        //   critical → blocker  (was top, now top)
        //   high     → critical
        //   medium   → major
        //   low      → minor
        DB::table('bugs')->where('priority', 'critical')->update(['priority' => 'blocker']);
        DB::table('bugs')->where('priority', 'high')->update(['priority' => 'critical']);
        DB::table('bugs')->where('priority', 'medium')->update(['priority' => 'major']);
        DB::table('bugs')->where('priority', 'low')->update(['priority' => 'minor']);
    }

    public function down(): void
    {
        // Reverse the priority remap
        DB::table('bugs')->where('priority', 'minor')->update(['priority' => 'low']);
        DB::table('bugs')->where('priority', 'major')->update(['priority' => 'medium']);
        DB::table('bugs')->where('priority', 'critical')->update(['priority' => 'high']);
        DB::table('bugs')->where('priority', 'blocker')->update(['priority' => 'critical']);

        // Note: we cannot recover the bugs that were originally severity='critical' —
        // they were merged into 'high'. This rollback restores all current 'high' to 'high'
        // (no-op for severity), accepting the lossy floor. If you need exact rollback,
        // restore from backup file storage/backups/bugs-pre-vocab-*.sql instead.
    }
};
