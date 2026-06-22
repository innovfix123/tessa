<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const SWAPNA_USER_ID = 55;

    private const RENAMES = [
        'thedal_daily_ad_spend' => [
            'onlycare' => ['field_label' => 'Onlycare daily ad spend (INR)', 'group_name' => 'Onlycare - Ad Spend'],
            'thedal'   => ['field_label' => 'Thedal daily ad spend (INR)',   'group_name' => 'Thedal - Ad Spend'],
        ],
        'thedal_cpa' => [
            'onlycare' => ['field_label' => 'Onlycare CPA (Cost per Acquisition)', 'group_name' => 'Onlycare - Efficiency'],
            'thedal'   => ['field_label' => 'Thedal CPA (Cost per Acquisition)',   'group_name' => 'Thedal - Efficiency'],
        ],
        'thedal_cpp' => [
            'onlycare' => ['field_label' => 'Onlycare CPP (Cost per Purchase)', 'group_name' => 'Onlycare - Efficiency'],
            'thedal'   => ['field_label' => 'Thedal CPP (Cost per Purchase)',   'group_name' => 'Thedal - Efficiency'],
        ],
        'thedal_new_installs' => [
            'onlycare' => ['field_label' => 'Onlycare new installs', 'group_name' => 'Onlycare - Funnel'],
            'thedal'   => ['field_label' => 'Thedal new installs',   'group_name' => 'Thedal - Funnel'],
        ],
        'thedal_registrations_from_paid' => [
            'onlycare' => ['field_label' => 'Onlycare registrations from paid', 'group_name' => 'Onlycare - Funnel'],
            'thedal'   => ['field_label' => 'Thedal registrations from paid',   'group_name' => 'Thedal - Funnel'],
        ],
    ];

    public function up(): void
    {
        $this->apply('onlycare');
    }

    public function down(): void
    {
        $this->apply('thedal');
    }

    private function apply(string $target): void
    {
        DB::transaction(function () use ($target) {
            $now = now();
            foreach (self::RENAMES as $key => $variants) {
                DB::table('kpi_definitions')
                    ->where('user_id', self::SWAPNA_USER_ID)
                    ->where('field_key', $key)
                    ->update($variants[$target] + ['updated_at' => $now]);
            }
        });
    }
};
