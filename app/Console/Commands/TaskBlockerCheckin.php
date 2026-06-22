<?php

namespace App\Console\Commands;

use App\Models\TessaTask;
use App\Models\TaskMessage;
use App\Services\SlackService;
use App\Helpers\DateHelper;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class TaskBlockerCheckin extends Command
{
    protected $signature = 'tasks:blocker-checkin';

    protected $description = 'Send smart midway blocker check-ins for active tasks via Slack';

    public function handle(): int
    {
        $now = Carbon::now('Asia/Kolkata');

        // Only run on weekdays
        if ($now->isWeekend()) {
            return 0;
        }

        $tasks = TessaTask::whereIn('status', TessaTask::ACTIVE_STATUSES)
            ->whereNotNull('deadline')
            ->where('deadline', '>', $now)
            ->with(['assignedTo', 'assignedBy'])
            ->get();

        $slack = new SlackService;
        $checked = 0;

        foreach ($tasks as $task) {
            if (! $task->assignedTo) {
                continue;
            }

            // Calculate if it's time for a check-in
            if (! $this->shouldCheckin($task, $now)) {
                continue;
            }

            // Check for recent thread activity (counts as passive update)
            $lastMessage = $task->messages()
                ->where('user_id', $task->assigned_to)
                ->orderByDesc('created_at')
                ->first();

            $daysSinceActivity = $lastMessage
                ? $lastMessage->created_at->timezone('Asia/Kolkata')->diffInDays($now)
                : $task->created_at->timezone('Asia/Kolkata')->diffInDays($now);

            // If assignee posted in thread within last 24h, mark as on_track, skip nudge
            if ($lastMessage && $daysSinceActivity < 1) {
                if ($task->blocker_status !== 'on_track') {
                    $task->update([
                        'blocker_status' => 'on_track',
                        'last_checkin_at' => $now,
                        'next_checkin_at' => $this->calcNextCheckin($task, $now),
                    ]);
                }
                continue;
            }

            // Send blocker check-in DM to assignee
            $daysLeft = $now->diffInDays($task->deadline, false);
            $assignerName = $task->assignedBy->name ?? 'your manager';
            $message = "Hey {$task->assignedTo->name}! Quick check on *{$task->title}*"
                . ($daysLeft <= 2 ? " (due in {$daysLeft} day" . ($daysLeft !== 1 ? 's' : '') . ')' : '')
                . " — any blockers?\n\nReply:\n• *on track* — all good\n• Or tell me what's blocking you\n\n_Assigned by {$assignerName}_";

            $slackId = $slack->getUserIdByName($task->assignedTo->name);
            if ($slackId && $slack->sendDirectMessage($slackId, $message)) {
                $task->update([
                    'blocker_status' => 'no_update',
                    'last_checkin_at' => $now,
                    'next_checkin_at' => $this->calcNextCheckin($task, $now),
                ]);
                $checked++;
                Log::info('TaskBlockerCheckin: sent', ['task_id' => $task->id, 'user' => $task->assignedTo->name]);
            }
        }

        // Also flag tasks with no activity for 2+ days to assigner
        $this->flagSilentTasks($now, $slack);

        $this->info("Checked in on {$checked} task(s).");

        return 0;
    }

    private function shouldCheckin(TessaTask $task, Carbon $now): bool
    {
        // If next_checkin_at is set and hasn't arrived yet, skip
        if ($task->next_checkin_at && $now->lt($task->next_checkin_at)) {
            return false;
        }

        $created = $task->created_at;
        $deadline = $task->deadline;
        $totalDays = $created->timezone('Asia/Kolkata')->diffInDays($deadline);
        $elapsed = $created->timezone('Asia/Kolkata')->diffInDays($now);

        if ($totalDays <= 1) {
            // Same-day or next-day task: check in at deadline - 2 hours (handled by overdue)
            return false;
        }

        if ($totalDays <= 3) {
            // Short task (2-3 days): one check-in at midpoint
            return $elapsed >= 1 && ! $task->last_checkin_at;
        }

        // Longer tasks: check-in at ~40% and ~75% of timeline
        $firstCheckin = (int) ceil($totalDays * 0.4);
        $secondCheckin = (int) ceil($totalDays * 0.75);

        $checkinCount = $task->last_checkin_at
            ? ($elapsed >= $secondCheckin ? 2 : 1)
            : 0;

        if ($checkinCount === 0 && $elapsed >= $firstCheckin) {
            return true;
        }

        if ($checkinCount <= 1 && $elapsed >= $secondCheckin && $task->last_checkin_at) {
            return $task->last_checkin_at->timezone('Asia/Kolkata')->diffInDays($now) >= 1;
        }

        return false;
    }

    private function calcNextCheckin(TessaTask $task, Carbon $now): Carbon
    {
        $deadline = $task->deadline;
        $daysLeft = $now->diffInDays($deadline, false);

        if ($daysLeft <= 2) {
            // Close to deadline: next check-in on deadline day
            return $deadline->copy()->startOfDay()->setHour(10);
        }

        // Otherwise, check in again at 75% mark or 2 days before deadline
        $totalDays = $task->created_at->timezone('Asia/Kolkata')->diffInDays($deadline);
        $secondMark = $task->created_at->copy()->addDays((int) ceil($totalDays * 0.75));

        return $secondMark->gt($now) ? $secondMark : $deadline->copy()->subDays(2)->setHour(10);
    }

    private function flagSilentTasks(Carbon $now, SlackService $slack): void
    {
        // Find tasks where assignee hasn't posted anything in 2+ days
        $tasks = TessaTask::whereIn('status', TessaTask::ACTIVE_STATUSES)
            ->whereNotNull('deadline')
            ->where('deadline', '>', $now)
            ->where('blocker_status', 'no_update')
            ->whereNotNull('last_checkin_at')
            ->where('last_checkin_at', '<', $now->copy()->subHours(36))
            ->with(['assignedTo', 'assignedBy'])
            ->get();

        foreach ($tasks as $task) {
            if (! $task->assignedBy) {
                continue;
            }

            $daysLeft = $now->diffInDays($task->deadline, false);
            $assigneeName = $task->assignedTo->name ?? 'Assignee';

            $message = "Heads up — *{$assigneeName}* hasn't responded to the check-in on *{$task->title}*"
                . " ({$daysLeft} day" . ($daysLeft !== 1 ? 's' : '') . " left). Might need a direct check-in.";

            $slackId = $slack->getUserIdByName($task->assignedBy->name);
            if ($slackId) {
                $slack->sendDirectMessage($slackId, $message);
                Log::info('TaskBlockerCheckin: flagged silent task to assigner', ['task_id' => $task->id]);
            }
        }
    }
}
