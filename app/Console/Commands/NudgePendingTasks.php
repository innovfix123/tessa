<?php

namespace App\Console\Commands;

use App\Models\TessaTask;
use App\Services\SlackService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class NudgePendingTasks extends Command
{
    protected $signature = 'tasks:nudge-pending';

    protected $description = 'Remind assignees about pending/in-progress tasks due within 24 hours';

    public function handle(): int
    {
        $now = Carbon::now('Asia/Kolkata');

        if ($now->isWeekend()) {
            return 0;
        }

        $cutoff = $now->copy()->addHours(24);

        $tasks = TessaTask::whereIn('status', TessaTask::ACTIVE_STATUSES)
            ->whereNotNull('deadline')
            ->where('deadline', '<=', $cutoff)
            ->where('deadline', '>=', $now)
            ->with(['assignedTo', 'assignedBy'])
            ->get();

        $slack = new SlackService;

        // Bundle by assignee so each user gets one DM listing all their due tasks.
        $buckets = [];

        foreach ($tasks as $task) {
            if (! $task->assignedTo) {
                continue;
            }

            $userId = $task->assignedTo->id;
            if (! isset($buckets[$userId])) {
                $buckets[$userId] = [
                    'name' => $task->assignedTo->name,
                    'lines' => [],
                ];
            }

            $hoursLeft = (int) $now->diffInHours($task->deadline);
            $timeLeft = $hoursLeft > 0 ? "{$hoursLeft}h left" : 'due soon';

            $buckets[$userId]['lines'][] = "• *{$task->title}*\n"
                ."   Status: ".str_replace('_', ' ', $task->status)
                ." · Due: {$task->deadline->timezone('Asia/Kolkata')->format('D, j M \\a\\t g:i A')} ({$timeLeft})";

            $task->increment('remind_count');
        }

        $nudged = 0;
        foreach ($buckets as $bucket) {
            $slackId = $slack->getUserIdByName($bucket['name']);
            if (! $slackId) {
                continue;
            }

            $count = count($bucket['lines']);
            $msg = "*Reminder: {$count} task(s) due within 24 hours*\n\n"
                .implode("\n", $bucket['lines'])
                ."\n\nPlease complete or update each in Tessa.";

            if ($slack->sendDirectMessage($slackId, $msg)) {
                $nudged++;
            }
        }

        $this->info("Nudged {$nudged} user(s) about pending tasks.");
        Log::info("NudgePendingTasks: nudged {$nudged} users (".count($buckets).' buckets, '.$tasks->count().' tasks)');

        return 0;
    }
}
