<?php

namespace App\Console\Commands;

use App\Models\RevenuePayout;
use App\Services\HimaAnalyticsService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SyncMissionRevenue extends Command
{
    protected $signature = 'revenue:sync-mission {--from= : Override FY start (YYYY-MM-DD)} {--to= : Override end date (YYYY-MM-DD)}';

    protected $description = 'Sync FY-to-date Hima revenue into revenue_payouts. Runs every 5 min so Mission dashboard reads from DB, not flaky upstream.';

    private const FY_START = '2026-04-01';

    public function handle(HimaAnalyticsService $hima): int
    {
        $from = $this->option('from') ?: self::FY_START;
        $to   = $this->option('to') ?: Carbon::now('Asia/Kolkata')->format('Y-m-d');

        $data = $hima->getDailyRevenue($from, $to);
        if ($data === null) {
            $this->error("Hima daily-revenue returned no data for {$from} → {$to}");
            return self::FAILURE;
        }

        $upserted = 0;
        foreach ($data['days'] as $row) {
            $date = $row['date'] ?? null;
            if (! $date) {
                continue;
            }

            $existing = RevenuePayout::where('date', $date)->first();
            $payload = [
                'revenue' => (int) ($row['revenue'] ?? 0),
                'paying_users' => (int) ($row['paying_users'] ?? 0),
                'transactions' => (int) ($row['transactions'] ?? 0),
                'by_language' => $row['by_language'] ?? null,
            ];

            if ($existing) {
                $existing->update($payload);
            } else {
                RevenuePayout::create(array_merge($payload, [
                    'date' => $date,
                    'payout_paid' => 0,
                    'payout_paid_count' => 0,
                    'payout_rejected' => 0,
                    'payout_rejected_count' => 0,
                    'payout_pending' => 0,
                    'payout_pending_count' => 0,
                    'audio_duration_sec' => 0,
                    'video_duration_sec' => 0,
                    'audio_minutes' => 0,
                    'video_minutes' => 0,
                    'agora_audio_cost_usd' => 0,
                    'agora_video_cost_usd' => 0,
                    'agora_total_cost_usd' => 0,
                    'agora_total_cost_inr' => 0,
                    'usd_inr_rate' => 0,
                ]));
            }
            $upserted++;
        }

        $this->info("Synced {$upserted} days. FY-to-date: ₹".number_format((int)($data['total_revenue'] ?? 0)));
        return self::SUCCESS;
    }
}
