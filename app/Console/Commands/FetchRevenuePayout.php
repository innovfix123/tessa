<?php

namespace App\Console\Commands;

use App\Models\AgoraPricingTier;
use App\Models\RevenuePayout;
use App\Services\HimaAnalyticsService;
use App\Helpers\DateHelper;
use Carbon\Carbon;
use Illuminate\Console\Command;

class FetchRevenuePayout extends Command
{
    protected $signature = 'revenue:fetch {date? : Date to fetch (YYYY-MM-DD), defaults to yesterday} {--usd-inr=85 : USD to INR exchange rate} {--monthly-audio= : Override monthly audio minutes for tier selection} {--monthly-video= : Override monthly video minutes for tier selection}';
    protected $description = 'Fetch daily revenue, payout & calls from Hima API, calculate Agora cost, and save to DB';

    public function handle(): int
    {
        $date = $this->argument('date')
            ?? Carbon::yesterday('Asia/Kolkata')->format('Y-m-d');
        $usdInrRate = (float) $this->option('usd-inr');

        $this->info("Fetching data for {$date} (USD/INR: {$usdInrRate})...");

        $service = new HimaAnalyticsService();

        // 1. Fetch revenue & payout
        $data = $service->getDailyRevenuePayout($date);
        if ($data === null) {
            $this->error("Revenue API returned no data for {$date}");
            return self::FAILURE;
        }

        // 2. Fetch daily calls
        $calls = $service->getDailyCalls($date);
        if ($calls === null) {
            $this->warn("Calls API returned no data for {$date}, saving with zero Agora cost");
        }

        $rev = $data['revenue'] ?? [];
        $paid = $data['payout']['paid'] ?? [];
        $rejected = $data['payout']['rejected'] ?? [];
        $pending = $data['payout']['pending'] ?? [];

        // Use combined (user + creator) duration — Agora bills both participants
        $audioSec = $calls['audio']['combined_duration_seconds'] ?? 0;
        $videoSec = $calls['video']['combined_duration_seconds'] ?? 0;
        $audioMin = (int) ceil($audioSec / 60);
        $videoMin = (int) ceil($videoSec / 60);

        // 3. Calculate Agora cost — use monthly volume for tier selection
        $month = DateHelper::parse($date);
        $monthStart = $month->copy()->startOfMonth()->format('Y-m-d');
        $monthEnd = $month->copy()->endOfMonth()->format('Y-m-d');

        // Use override if provided, otherwise sum from DB
        $monthlyAudio = $this->option('monthly-audio')
            ? (int) $this->option('monthly-audio')
            : (int) RevenuePayout::whereBetween('date', [$monthStart, $monthEnd])
                ->where('date', '!=', $date)
                ->sum('audio_minutes') + $audioMin;
        $monthlyVideo = $this->option('monthly-video')
            ? (int) $this->option('monthly-video')
            : (int) RevenuePayout::whereBetween('date', [$monthStart, $monthEnd])
                ->where('date', '!=', $date)
                ->sum('video_minutes') + $videoMin;

        $this->info("Monthly volume so far — Audio: " . number_format($monthlyAudio) . " min, Video: " . number_format($monthlyVideo) . " min");
        $this->info("Audio tier: \$" . AgoraPricingTier::getRateForVolume($monthlyAudio, 'audio') . "/1000 min");
        $this->info("Video tier: \$" . AgoraPricingTier::getRateForVolume($monthlyVideo, 'video_hd') . "/1000 min");

        $audioCostUsd = AgoraPricingTier::calculateDailyCost($audioMin, $monthlyAudio, 'audio');
        $videoCostUsd = AgoraPricingTier::calculateDailyCost($videoMin, $monthlyVideo, 'video_hd');
        $totalCostUsd = round($audioCostUsd + $videoCostUsd, 2);
        $totalCostInr = round($totalCostUsd * $usdInrRate, 2);

        // 4. Save everything
        RevenuePayout::updateOrCreate(
            ['date' => $date],
            [
                'revenue' => $rev['total'] ?? 0,
                'paying_users' => $rev['paying_users'] ?? 0,
                'transactions' => $rev['transactions'] ?? 0,
                'by_language' => $rev['by_language'] ?? [],
                'payout_paid' => $paid['total_amount'] ?? 0,
                'payout_paid_count' => $paid['count'] ?? 0,
                'payout_rejected' => $rejected['total_amount'] ?? 0,
                'payout_rejected_count' => $rejected['count'] ?? 0,
                'payout_pending' => $pending['total_amount'] ?? 0,
                'payout_pending_count' => $pending['count'] ?? 0,
                'audio_duration_sec' => $audioSec,
                'video_duration_sec' => $videoSec,
                'audio_minutes' => $audioMin,
                'video_minutes' => $videoMin,
                'agora_audio_cost_usd' => $audioCostUsd,
                'agora_video_cost_usd' => $videoCostUsd,
                'agora_total_cost_usd' => $totalCostUsd,
                'agora_total_cost_inr' => $totalCostInr,
                'usd_inr_rate' => $usdInrRate,
            ]
        );

        $this->info("Revenue: ₹" . number_format($rev['total'] ?? 0));
        $this->info("Payout:  ₹" . number_format($paid['total_amount'] ?? 0, 2));
        $this->info("Audio:   {$audioMin} min → \${$audioCostUsd} USD");
        $this->info("Video:   {$videoMin} min → \${$videoCostUsd} USD");
        $this->info("Agora:   \${$totalCostUsd} USD → ₹" . number_format($totalCostInr, 2) . " INR");

        return self::SUCCESS;
    }
}
