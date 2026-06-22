<?php

namespace App\Console\Commands;

use App\Models\DailyReport;
use App\Models\User;
use App\Services\HimaAnalyticsService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SyncHimaConversions extends Command
{
    protected $signature = 'sync:hima-conversions {--date= : Date (Y-m-d), defaults to yesterday}';

    protected $description = 'Fetch Hima conversion metrics from analytics API and populate daily reports';

    /** System user ID for updated_by */
    private const SYSTEM_USER_ID = 1;

    public function handle(HimaAnalyticsService $service): int
    {
        $dateStr = $this->option('date')
            ?: Carbon::now('Asia/Kolkata')->subDay()->format('Y-m-d');

        $this->info("Fetching Hima conversion data for {$dateStr}...");

        $data = null;
        $maxRetries = 3;
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            $data = $service->getConversion($dateStr);
            if ($data) {
                break;
            }
            if ($attempt < $maxRetries) {
                $this->warn("Attempt {$attempt} failed, retrying in 10s...");
                sleep(10);
            }
        }

        if (! $data) {
            $this->error('Failed to fetch data from Hima Analytics API after 3 attempts.');
            return self::FAILURE;
        }

        $byUser = HimaAnalyticsService::mapToKpiFields($data);
        if (empty($byUser)) {
            $this->warn('No data found in API response.');
            return self::SUCCESS;
        }

        $totalSaved = 0;
        foreach ($byUser as $userId => $fields) {
            $userName = User::find($userId)?->name ?? "User #{$userId}";
            $this->info("  {$userName}:");

            foreach ($fields as $fieldKey => $value) {
                DailyReport::updateOrCreate(
                    [
                        'user_id' => $userId,
                        'report_date' => $dateStr,
                        'field_key' => $fieldKey,
                    ],
                    [
                        'value' => $value,
                        'updated_by' => self::SYSTEM_USER_ID,
                    ]
                );
                $totalSaved++;
                $this->line("    {$fieldKey} = {$value}");
            }
        }

        $this->info("Done. Saved {$totalSaved} fields for {$dateStr}.");

        return self::SUCCESS;
    }
}
