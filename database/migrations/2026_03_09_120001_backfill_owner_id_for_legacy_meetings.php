<?php

use App\Models\Meeting;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Backfill owner_id for meetings where created_by was 0 (legacy data).
     * Match by owner name and prefer user whose role matches meeting portal.
     */
    public function up(): void
    {
        Meeting::whereNull('owner_id')
            ->orWhere('owner_id', 0)
            ->each(function (Meeting $meeting) {
                $ownerUser = User::where(function ($q) use ($meeting) {
                    $q->where('name', $meeting->owner)
                        ->orWhere('name', 'like', $meeting->owner . ' %');
                })
                    ->whereHas('roleRelation', fn ($q) => $q->where('slug', $meeting->portal))
                    ->orderByRaw('LENGTH(name) DESC')
                    ->first()
                    ?? User::where('name', $meeting->owner)->first();

                if ($ownerUser) {
                    $meeting->update(['owner_id' => $ownerUser->id]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No-op: we cannot reliably revert the backfill
    }
};
