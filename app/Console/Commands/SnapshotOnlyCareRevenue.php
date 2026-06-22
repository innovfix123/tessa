<?php

namespace App\Console\Commands;

use App\Models\OnlyCareRevenueSnapshot;
use App\Services\OnlyCareAnalyticsService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SnapshotOnlyCareRevenue extends Command
{
    protected $signature = 'onlycare:snapshot-revenue';

    protected $description = 'Snapshot OnlyCare lifetime revenue into onlycare_revenue_snapshots';

    public function handle(OnlyCareAnalyticsService $service): int
    {
        $data = $service->getTotalRevenue();
        if (! $data) {
            $this->error('OnlyCare API returned no data.');

            return self::FAILURE;
        }

        $today = Carbon::now('Asia/Kolkata')->toDateString();

        OnlyCareRevenueSnapshot::updateOrCreate(
            ['snapshot_date' => $today],
            [
                'total_revenue' => (int) $data['total_revenue'],
                'transactions_count' => (int) $data['transactions_count'],
                'last_transaction_at' => $data['last_transaction_at'] ?? null,
                'source_as_of' => $data['as_of'] ?? null,
            ]
        );

        $this->info("Stored OnlyCare snapshot for {$today}: ₹".number_format($data['total_revenue']));

        return self::SUCCESS;
    }
}
