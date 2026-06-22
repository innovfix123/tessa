<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\KpiDefinition;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Get growth_manager role
        $growthManagerRole = DB::table('roles')->where('slug', 'growth_manager')->first();
        if (!$growthManagerRole) {
            return;
        }

        // 2. Get Nandha (CMO) as reporting manager
        $nandha = User::where('email', 'nandha@innovfix.in')->first();
        if (!$nandha) {
            return;
        }

        // 3. Create or update Anindita user
        $anindita = User::updateOrCreate(
            ['email' => 'anindita@innovfix.in'],
            [
                'name' => 'Anindita',
                'password_hash' => password_hash('12345678', PASSWORD_BCRYPT),
                'role_id' => $growthManagerRole->id,
                'reporting_manager_id' => $nandha->id,
                'is_active' => true,
            ]
        );

        // 4. Seed KPI definitions for Anindita (North India Growth)
        $northIndiaFields = [
            ['key' => 'north_india_revenue', 'label' => 'North India Revenue (INR)', 'aggregation' => 'sum'],
            ['key' => 'north_india_registrations', 'label' => 'New Registrations (North India)', 'aggregation' => 'sum'],
            ['key' => 'north_india_paid_users', 'label' => 'Paid Users (North India)', 'aggregation' => 'sum'],
            ['key' => 'north_india_active_users', 'label' => 'Active Users (North India)', 'aggregation' => 'latest'],
            ['key' => 'campaigns_run', 'label' => 'Campaigns Run', 'aggregation' => 'sum'],
            ['key' => 'north_india_ad_spend', 'label' => 'Ad Spend - North India (INR)', 'aggregation' => 'sum'],
            ['key' => 'north_india_cpa', 'label' => 'CPA - North India (INR)', 'aggregation' => 'avg'],
        ];

        $sortOrder = 0;
        foreach ($northIndiaFields as $field) {
            $exists = KpiDefinition::where('user_id', $anindita->id)
                ->where('field_key', $field['key'])
                ->exists();

            if (!$exists) {
                KpiDefinition::create([
                    'user_id' => $anindita->id,
                    'group_name' => 'North India Growth',
                    'field_key' => $field['key'],
                    'field_label' => $field['label'],
                    'aggregation' => $field['aggregation'],
                    'sort_order' => $sortOrder++,
                    'created_by' => $anindita->id,
                ]);
            }
        }
    }

    public function down(): void
    {
        $anindita = User::where('email', 'anindita@innovfix.in')->first();
        if ($anindita) {
            KpiDefinition::where('user_id', $anindita->id)->delete();
            DB::table('daily_reports')->where('user_id', $anindita->id)->delete();
            $anindita->delete();
        }
    }
};
