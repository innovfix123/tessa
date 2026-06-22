<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const SWAPNA_USER_ID = 55;
    private const FIELD_KEY      = 'onlycare_total_verified_users';
    private const FIELD_LABEL    = 'Total verified users';
    private const GROUP_NAME     = 'Onlycare - Efficiency';
    private const INSERT_AT      = 8;

    public function up(): void
    {
        DB::transaction(function () {
            DB::table('kpi_definitions')
                ->where('user_id', self::SWAPNA_USER_ID)
                ->where('sort_order', '>=', self::INSERT_AT)
                ->increment('sort_order');

            $now = now();
            DB::table('kpi_definitions')->insert([
                'user_id'     => self::SWAPNA_USER_ID,
                'group_name'  => self::GROUP_NAME,
                'field_key'   => self::FIELD_KEY,
                'field_label' => self::FIELD_LABEL,
                'aggregation' => 'avg',
                'input_type'  => 'text',
                'auto_sync'   => 0,
                'optional'    => 0,
                'sort_order'  => self::INSERT_AT,
                'created_by'  => 34,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            DB::table('kpi_definitions')
                ->where('user_id', self::SWAPNA_USER_ID)
                ->where('field_key', self::FIELD_KEY)
                ->delete();

            DB::table('kpi_definitions')
                ->where('user_id', self::SWAPNA_USER_ID)
                ->where('sort_order', '>', self::INSERT_AT)
                ->decrement('sort_order');
        });
    }
};
