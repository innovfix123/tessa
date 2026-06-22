<?php

namespace App\Console\Commands;

use App\Models\DailyReport;
use App\Services\HimaAnalyticsService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SyncHimaPaidUsers extends Command
{
    protected $signature = 'sync:hima-paid-users {--date= : Date (Y-m-d), defaults to yesterday}';

    protected $description = 'Fetch Hima paid registered users by language and populate daily reports for Anirudh, Sneha, and Anindita';

    private const ANIRUDH_USER_ID = 11;
    private const SNEHA_USER_ID = 5;
    private const ANINDITA_USER_ID = 17;
    private const SYSTEM_USER_ID = 1;

    private const LANGUAGE_MAP = [
        'Tamil' => 'tamil',
        'Telugu' => 'telugu',
        'Kannada' => 'kannada',
        'Malayalam' => 'malayalam',
        'Bengali' => 'bengali',
        'Hindi' => 'hindi',
    ];

    // analytics.himaapp.in/api/conversion has been 403-blocked since 2026-04-28, so we
    // compute avg_paying_amount from paid-registered-by-language (total_amount / users)
    // instead. The conversion_pct fields stay manual until the analytics API comes back.
    private const AVG_AMOUNT_FIELDS = [
        self::SNEHA_USER_ID => [
            'Tamil' => 'tamil_new_users_avg_paying_amount',
            'Telugu' => 'telugu_new_users_avg_paying_amount',
            'Kannada' => 'kannada_new_users_avg_paying_amount',
            'Malayalam' => 'malayalam_new_users_avg_paying_amount',
        ],
        self::ANINDITA_USER_ID => [
            'Bengali' => 'bengali_avg_paying_amount',
            'Hindi' => 'hindi_avg_paying_amount',
        ],
    ];

    public function handle(HimaAnalyticsService $service): int
    {
        $dateStr = $this->option('date')
            ?: Carbon::now('Asia/Kolkata')->subDay()->format('Y-m-d');

        $this->info("Fetching Hima paid registered users for {$dateStr}...");

        $apiFailed = false;
        $data = null;
        $maxRetries = 3;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            $data = $service->getPaidRegisteredByLanguage($dateStr);
            if ($data && ($data['success'] ?? false)) {
                break;
            }
            if ($attempt < $maxRetries) {
                $this->warn("Attempt {$attempt} failed, retrying in 10s...");
                sleep(10);
            }
        }

        if (! $data || ! ($data['success'] ?? false)) {
            $this->error('Failed to fetch data from Hima API after 3 attempts.');
            $apiFailed = true;
        }

        $saved = 0;
        if (! $apiFailed) {
            $byLanguage = [];
            foreach ($data['data'] ?? [] as $entry) {
                $byLanguage[$entry['language']] = $entry;
            }

            foreach (self::LANGUAGE_MAP as $langName => $prefix) {
                $value = $byLanguage[$langName]['total_paid_registered_users'] ?? null;
                if ($value === null) {
                    $this->warn("  No data for {$langName}");
                    continue;
                }

                $fieldKey = "{$prefix}_total_paid_registered_users";
                DailyReport::updateOrCreate(
                    [
                        'user_id' => self::ANIRUDH_USER_ID,
                        'report_date' => $dateStr,
                        'field_key' => $fieldKey,
                    ],
                    [
                        'value' => (string) $value,
                        'updated_by' => self::SYSTEM_USER_ID,
                    ]
                );
                $saved++;
                $this->line("  {$fieldKey} = {$value}");
            }

            foreach (self::AVG_AMOUNT_FIELDS as $userId => $langToField) {
                foreach ($langToField as $langName => $fieldKey) {
                    $users = (int) ($byLanguage[$langName]['total_paid_registered_users'] ?? 0);
                    $amount = (float) ($byLanguage[$langName]['total_amount'] ?? 0);
                    if ($users <= 0) {
                        continue;
                    }
                    $avg = (string) (int) round($amount / $users);

                    DailyReport::updateOrCreate(
                        [
                            'user_id' => $userId,
                            'report_date' => $dateStr,
                            'field_key' => $fieldKey,
                        ],
                        [
                            'value' => $avg,
                            'updated_by' => self::SYSTEM_USER_ID,
                        ]
                    );
                    $saved++;
                    $this->line("  user {$userId} {$fieldKey} = {$avg}");
                }
            }

            $this->info("Saved {$saved} paid-user fields for {$dateStr}.");
        }

        $cpaCount = HimaAnalyticsService::recalculateCpa($dateStr, self::SYSTEM_USER_ID);
        $this->info("Recalculated {$cpaCount} CPA fields.");

        return $apiFailed ? self::FAILURE : self::SUCCESS;
    }
}
