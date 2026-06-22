<?php

use App\Models\KpiDefinition;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;

/**
 * Sooraj's "Designs Delivered" image upload stays mandatory, but his
 * "Videos Delivered" upload becomes optional — he can't always produce a
 * video every day, and forcing one would otherwise block his daily sign-off.
 *
 * Why this flag is enough:
 *  - Sign-off: SignoffStatusService::getDefinedFieldsForUser filters
 *    `optional = false`, so the row stops counting toward the
 *    "X of Y fields filled" gate.
 *  - KRA / MTD: KraScorecardService::scoreDailyReports scores per-DAY
 *    (any non-empty value), not per-field, so an unfilled optional
 *    field never affects the discipline score either way.
 *
 * `optional` is not in KpiDefinition::$fillable, so we use the same
 * trick as the Ranjini migration (set the attribute, then save()) for
 * the firstOrCreate fallback; for an existing row a plain update() works.
 */
return new class extends Migration
{
    private const EMAIL = 'sooraj@innovfix.in';

    public function up(): void
    {
        $sooraj = User::where('email', self::EMAIL)->first();
        if (! $sooraj) {
            return;
        }

        KpiDefinition::where('user_id', $sooraj->id)
            ->where('field_key', 'videos_delivered')
            ->update(['optional' => true]);
    }

    public function down(): void
    {
        $sooraj = User::where('email', self::EMAIL)->first();
        if (! $sooraj) {
            return;
        }

        KpiDefinition::where('user_id', $sooraj->id)
            ->where('field_key', 'videos_delivered')
            ->update(['optional' => false]);
    }
};
