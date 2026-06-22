<?php

use App\Models\KpiDefinition;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    private const ANIRUDH_USER_ID = 11;

    // group_name => field-key prefix
    private const LANGUAGES = [
        'Tamil' => 'tamil',
        'Telugu' => 'telugu',
        'Kannada' => 'kannada',
        'Malayalam' => 'malayalam',
        'Bengali' => 'bengali',
        'Hindi' => 'hindi',
    ];

    /**
     * Hindi's spend row is the legacy un-prefixed `daily_spend`; every other
     * language uses `{prefix}_daily_ad_spend`.
     */
    private function spendKeyFor(string $prefix): string
    {
        return $prefix === 'hindi' ? 'daily_spend' : "{$prefix}_daily_ad_spend";
    }

    public function up(): void
    {
        foreach (self::LANGUAGES as $groupName => $prefix) {
            $newKey = "{$prefix}_daily_ad_spend_excl_gst";

            if (KpiDefinition::where('user_id', self::ANIRUDH_USER_ID)->where('field_key', $newKey)->exists()) {
                continue;
            }

            $spendDef = KpiDefinition::where('user_id', self::ANIRUDH_USER_ID)
                ->where('group_name', $groupName)
                ->where('field_key', $this->spendKeyFor($prefix))
                ->whereNull('deleted_at')
                ->first();

            if ($spendDef) {
                // Slot the new row directly beneath the Daily Spend row (above CPA):
                // bump everything after the spend row down by one, then insert.
                $spendSort = (int) $spendDef->sort_order;
                $aggregation = $spendDef->aggregation; // mirror spend row (currently 'sum')

                KpiDefinition::where('user_id', self::ANIRUDH_USER_ID)
                    ->where('group_name', $groupName)
                    ->where('sort_order', '>', $spendSort)
                    ->whereNull('deleted_at')
                    ->increment('sort_order');

                $sortOrder = $spendSort + 1;
            } else {
                // Defensive fallback: append at the end of the group.
                $aggregation = 'sum';
                $sortOrder = (KpiDefinition::where('user_id', self::ANIRUDH_USER_ID)
                    ->where('group_name', $groupName)
                    ->whereNull('deleted_at')
                    ->max('sort_order') ?? -1) + 1;
            }

            KpiDefinition::create([
                'user_id' => self::ANIRUDH_USER_ID,
                'group_name' => $groupName,
                'field_key' => $newKey,
                'field_label' => 'Daily Spend (excl. GST)',
                'aggregation' => $aggregation,
                'auto_sync' => true,
                'sort_order' => $sortOrder,
                'created_by' => 1,
            ]);
        }
    }

    public function down(): void
    {
        foreach (self::LANGUAGES as $prefix) {
            KpiDefinition::where('user_id', self::ANIRUDH_USER_ID)
                ->where('field_key', "{$prefix}_daily_ad_spend_excl_gst")
                ->forceDelete();
        }
    }
};
