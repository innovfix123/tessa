<?php

namespace App\Console\Commands;

use App\Models\TessaTask;
use App\Services\SlackService;
use App\Helpers\DateHelper;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class TaskEscalateOverdue extends Command
{
    protected $signature = 'tasks:escalate-overdue';

    protected $description = 'Notify assigners about overdue tasks and nudge assignees daily';

    public function handle(): int
    {
        $now = Carbon::now('Asia/Kolkata');

        if ($now->isWeekend()) {
            return 0;
        }

        $tasks = TessaTask::whereIn('status', TessaTask::ACTIVE_STATUSES)
            ->whereNotNull('deadline')
            ->where('deadline', '<', $now)
            ->with(['assignedTo', 'assignedBy'])
            ->get();

        $slack = new SlackService;

        // Two buckets keyed by recipient user id:
        //   $assigneeBuckets — overdue tasks assigned TO that user
        //   $assignerBuckets — overdue tasks (≥2 days) BY that user
        $assigneeBuckets = [];
        $assignerBuckets = [];

        foreach ($tasks as $task) {
            $daysOverdue = $task->deadline->timezone('Asia/Kolkata')->diffInDays($now);

            if ($task->assignedTo) {
                $uid = $task->assignedTo->id;
                if (! isset($assigneeBuckets[$uid])) {
                    $assigneeBuckets[$uid] = [
                        'name' => $task->assignedTo->name,
                        'lines' => [],
                    ];
                }
                $dayWord = $daysOverdue !== 1 ? 'days' : 'day';
                $assigneeBuckets[$uid]['lines'][] = "• *{$task->title}* — overdue by {$daysOverdue} {$dayWord}";
            }

            if ($daysOverdue >= 2 && $task->assignedBy) {
                $uid = $task->assignedBy->id;
                if (! isset($assignerBuckets[$uid])) {
                    $assignerBuckets[$uid] = [
                        'name' => $task->assignedBy->name,
                        'lines' => [],
                    ];
                }
                $assigneeName = $task->assignedTo->name ?? 'Unassigned';
                $line = "• *{$task->title}* → *{$assigneeName}* ({$daysOverdue} days overdue)";
                if ($task->blocker_note) {
                    $line .= "\n   Blocker: {$task->blocker_note}";
                } else {
                    $line .= "\n   No blocker reported";
                }
                if ($task->blocker_status) {
                    $line .= "\n   Status: {$task->blocker_status}";
                }
                $assignerBuckets[$uid]['lines'][] = $line;
            }

            $task->update([
                'reminded_at' => $now,
                'remind_count' => ($task->remind_count ?? 0) + 1,
            ]);
        }

        $assigneeSent = 0;
        foreach ($assigneeBuckets as $bucket) {
            $slackId = $slack->getUserIdByName($bucket['name']);
            if (! $slackId) {
                continue;
            }
            $count = count($bucket['lines']);
            $msg = "*Reminder: {$count} overdue task(s)*\n\n"
                .implode("\n", $bucket['lines'])
                ."\n\nPlease update progress or let your manager know about blockers.";
            if ($slack->sendDirectMessage($slackId, $msg)) {
                $assigneeSent++;
            }
        }

        $assignerSent = 0;
        foreach ($assignerBuckets as $bucket) {
            $slackId = $slack->getUserIdByName($bucket['name']);
            if (! $slackId) {
                continue;
            }
            $count = count($bucket['lines']);
            $msg = "*Alert: {$count} overdue task(s) you assigned*\n\n"
                .implode("\n\n", $bucket['lines']);
            if ($slack->sendDirectMessage($slackId, $msg)) {
                $assignerSent++;
            }
        }

        $this->info("Escalated {$tasks->count()} overdue task(s). Notified {$assigneeSent} assignee(s), {$assignerSent} assigner(s).");

        return 0;
    }
}
