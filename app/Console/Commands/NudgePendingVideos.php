<?php

namespace App\Console\Commands;

use App\Models\CreativeUpload;
use App\Models\User;
use App\Services\SlackService;
use App\Services\VideoHandoffNotifier;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Reminds Anaz on Slack about content-creator videos that are still waiting
 * for a reworked version. One bundled DM, one line per creator. Scoped to the
 * current week (Mon -> today) so abandoned earlier-week items stop nagging.
 */
class NudgePendingVideos extends Command
{
    protected $signature = 'videos:nudge-pending';

    protected $description = 'Remind Anaz on Slack about creator videos still pending a rework';

    public function handle(): int
    {
        $now = Carbon::now('Asia/Kolkata');

        if ($now->isWeekend()) {
            return 0;
        }

        $weekStart = $now->copy()->startOfWeek(Carbon::MONDAY)->format('Y-m-d');
        $today = $now->format('Y-m-d');

        $rawUploads = CreativeUpload::with(['handoffs', 'user:id,name'])
            ->whereIn('user_id', VideoHandoffNotifier::creatorIds())
            ->where('field_key', VideoHandoffNotifier::RAW_FIELD)
            ->whereBetween('report_date', [$weekStart, $today])
            ->whereNotNull('file_path')
            ->get();

        // Tally pending vs total raw videos per creator. A raw is "done" only
        // once all three crops (1:1, 9:16, 16:9) are uploaded; anything missing a
        // ratio still counts as pending. Legacy null-ratio reworks (from before
        // the per-ratio boxes) are grandfathered as done so old work doesn't nag.
        $tally = [];
        foreach ($rawUploads as $raw) {
            $cid = $raw->user_id;
            if (! isset($tally[$cid])) {
                $tally[$cid] = ['name' => $raw->user?->name ?? 'Unknown', 'total' => 0, 'pending' => 0];
            }
            $tally[$cid]['total']++;

            $ratios = $raw->handoffs->pluck('ratio');
            $complete = $ratios->contains(fn ($r) => $r === null || $r === '')
                || ($ratios->contains('1:1') && $ratios->contains('9:16') && $ratios->contains('16:9'));
            if (! $complete) {
                $tally[$cid]['pending']++;
            }
        }

        $lines = [];
        $totalPending = 0;
        foreach ($tally as $counts) {
            if ($counts['pending'] === 0) {
                continue;
            }
            $lines[] = "• {$counts['pending']}/{$counts['total']} videos still pending from {$counts['name']}";
            $totalPending += $counts['pending'];
        }

        if (empty($lines)) {
            $this->info('No pending video updates — no reminder sent.');

            return 0;
        }

        $anaz = User::find(VideoHandoffNotifier::ANAZ_USER_ID);
        if (! $anaz) {
            $this->error('Anaz user not found.');

            return 0;
        }

        $slack = new SlackService;
        $slackId = $anaz->slack_user_id ?: $slack->getUserIdByName($anaz->name);
        if (! $slackId) {
            $this->warn('Could not resolve Anaz on Slack — reminder skipped.');
            Log::warning('NudgePendingVideos: no Slack id for Anaz');

            return 0;
        }

        $msg = "*Reminder: {$totalPending} video(s) pending rework*\n\n"
            .implode("\n", $lines)
            ."\n\nOpen Tessa → Daily Reports to upload the updated versions.";

        // sendDirectMessage() respects the Slack quiet window on its own.
        $sent = $slack->sendDirectMessage($slackId, $msg);

        $this->info($sent
            ? "Reminded Anaz about {$totalPending} pending video(s)."
            : 'Reminder not sent (quiet window or Slack error).');
        Log::info('NudgePendingVideos: pending='.$totalPending.', sent='.($sent ? 'yes' : 'no'));

        return 0;
    }
}
