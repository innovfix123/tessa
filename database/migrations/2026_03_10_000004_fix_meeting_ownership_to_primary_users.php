<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Reassign meeting owner_ids from duplicate user accounts to primary accounts,
     * update Sneha owner name, and deactivate duplicates.
     */
    public function up(): void
    {
        $duplicateToPrimary = [
            29 => 1,  // JP@ceo -> JP@jp
            14 => 2,  // Bala@coo -> Bala@bala
            15 => 3,  // Nandha@cmo -> Nandha@nandha
            30 => 4,  // Ayush@cfo -> Ayush@ayush
            31 => 5,  // Sneha@ops -> Sneha Sunoj@sneha
        ];

        foreach ($duplicateToPrimary as $duplicateId => $primaryId) {
            DB::table('meetings')
                ->where('owner_id', $duplicateId)
                ->update(['owner_id' => $primaryId]);
        }

        // Update owner name on Sneha's meetings from "Sneha" to "Sneha Sunoj"
        DB::table('meetings')
            ->where('owner_id', 5)
            ->where('owner', 'Sneha')
            ->update(['owner' => 'Sneha Sunoj']);

        // Deactivate duplicate accounts
        DB::table('users')
            ->whereIn('id', [14, 15, 29, 30, 31])
            ->update(['is_active' => false]);
    }

    public function down(): void
    {
        DB::table('users')
            ->whereIn('id', [14, 15, 29, 30, 31])
            ->update(['is_active' => true]);

        $primaryToDuplicate = [
            1 => 29,
            2 => 14,
            3 => 15,
            4 => 30,
            5 => 31,
        ];

        foreach ($primaryToDuplicate as $primaryId => $duplicateId) {
            DB::table('meetings')
                ->where('owner_id', $primaryId)
                ->update(['owner_id' => $duplicateId]);
        }

        DB::table('meetings')
            ->where('owner_id', 31)
            ->where('owner', 'Sneha Sunoj')
            ->update(['owner' => 'Sneha']);
    }
};
