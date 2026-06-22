<?php

use App\Models\KpiDefinition;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    private const ANIRUDH_USER_ID = 11;

    private const LANGUAGES = [
        'Tamil' => 'tamil',
        'Telugu' => 'telugu',
        'Kannada' => 'kannada',
        'Malayalam' => 'malayalam',
        'Bengali' => 'bengali',
        'Hindi' => 'hindi',
    ];

    public function up(): void
    {
        foreach (self::LANGUAGES as $groupName => $prefix) {
            $fieldKey = "{$prefix}_total_paid_registered_users";

            if (KpiDefinition::where('user_id', self::ANIRUDH_USER_ID)->where('field_key', $fieldKey)->exists()) {
                continue;
            }

            $maxSort = KpiDefinition::where('user_id', self::ANIRUDH_USER_ID)
                ->where('group_name', $groupName)
                ->whereNull('deleted_at')
                ->max('sort_order') ?? -1;

            KpiDefinition::create([
                'user_id' => self::ANIRUDH_USER_ID,
                'group_name' => $groupName,
                'field_key' => $fieldKey,
                'field_label' => 'Total Paid Registered Users',
                'aggregation' => 'sum',
                'auto_sync' => true,
                'sort_order' => $maxSort + 1,
                'created_by' => 1,
            ]);
        }
    }

    public function down(): void
    {
        foreach (self::LANGUAGES as $prefix) {
            KpiDefinition::where('user_id', self::ANIRUDH_USER_ID)
                ->where('field_key', "{$prefix}_total_paid_registered_users")
                ->forceDelete();
        }
    }
};
