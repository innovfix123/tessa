<?php

namespace Database\Seeders;

use App\Models\KpiScorecardItem;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Seeds KPI scorecard definitions (name + monthly target + weight) from the
 * static reference doc public/kpis.html into kpi_scorecard_items.
 *
 * Data lives in database/data/kpi_scorecards.php (auto-generated from the HTML,
 * keyed by users.id). Idempotent: a user who already has KPI items is skipped,
 * so re-running never duplicates and never clobbers JP's later manual edits.
 *
 *   php artisan db:seed --class=KpiScorecardSeeder
 */
class KpiScorecardSeeder extends Seeder
{
    public function run(): void
    {
        $data = require database_path('data/kpi_scorecards.php');

        $created = 0;
        $skipped = 0;
        $missing = [];

        foreach ($data as $userId => $kpis) {
            $user = User::find($userId);
            if (! $user) {
                $missing[] = $userId;
                continue;
            }

            if (KpiScorecardItem::where('user_id', $userId)->exists()) {
                $skipped++;
                $this->command?->warn("Skip {$user->name} (#{$userId}) — already has KPI items.");
                continue;
            }

            foreach (array_values($kpis) as $i => $k) {
                KpiScorecardItem::create([
                    'user_id'     => $userId,
                    'name'        => $k['name'],
                    'description' => $k['description'] !== '' ? $k['description'] : null,
                    'target'      => $k['target'] ?? null,
                    'weight'      => $k['weight'] ?? null,
                    'sort_order'  => $i,
                    'is_active'   => true,
                    'created_by'  => null,
                ]);
                $created++;
            }
            $this->command?->info("Seeded " . count($kpis) . " KPIs for {$user->name} (#{$userId}).");
        }

        $this->command?->info("KPI scorecard seed done: {$created} items created, {$skipped} users skipped.");
        if ($missing) {
            $this->command?->error('Unknown user ids (not seeded): ' . implode(', ', $missing));
        }
    }
}
