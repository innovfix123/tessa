<?php

namespace App\Services;

use App\Models\TaskParticipant;
use App\Models\TessaTask;
use App\Helpers\DateHelper;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TessaTaskService
{
    public function __construct(
        private SlackService $slackService
    ) {}

    /**
     * Detect whether the given dependency IDs would create a cycle.
     * Walks forward from each candidate; if it reaches the source task, that's a cycle.
     */
    public static function wouldCreateDependencyCycle(TessaTask $task, array $newDepIds): bool
    {
        $newDepIds = array_values(array_unique(array_filter(array_map('intval', $newDepIds))));
        if (in_array($task->id, $newDepIds, true)) {
            return true;
        }

        foreach ($newDepIds as $startId) {
            $visited = [];
            $stack = [$startId];
            while ($stack) {
                $cur = array_pop($stack);
                if ($cur === $task->id) {
                    return true;
                }
                if (isset($visited[$cur])) {
                    continue;
                }
                $visited[$cur] = true;
                $children = DB::table('task_dependencies')
                    ->where('task_id', $cur)
                    ->pluck('depends_on_task_id')
                    ->all();
                foreach ($children as $c) {
                    $stack[] = (int) $c;
                }
            }
        }

        return false;
    }

    /**
     * Sync the task's dependency list. Rejects cycles.
     */
    public static function syncTaskDependencies(TessaTask $task, array $depIds): array
    {
        $depIds = array_values(array_unique(array_filter(array_map('intval', $depIds))));
        $depIds = array_values(array_filter($depIds, fn ($id) => $id !== $task->id));

        if (self::wouldCreateDependencyCycle($task, $depIds)) {
            return ['ok' => false, 'error' => 'Adding these dependencies would create a circular blockage.'];
        }

        $task->dependencies()->sync($depIds);

        return ['ok' => true];
    }

    /**
     * Sync the set of tasks that this task is blocking.
     * Each blocking task gets a row (task_id=blockingId, depends_on_task_id=$task->id).
     * Rejects cycles.
     */
    public static function syncTaskBlocking(TessaTask $task, array $blockingIds): array
    {
        $blockingIds = array_values(array_unique(array_filter(array_map('intval', $blockingIds))));
        $blockingIds = array_values(array_filter($blockingIds, fn ($id) => $id !== $task->id));

        // A cycle would mean: this task already (transitively) depends on one of the tasks
        // we're about to block. Walking forward from $task->id should not reach any blocking ID.
        $visited = [];
        $stack = [$task->id];
        while ($stack) {
            $cur = array_pop($stack);
            if (isset($visited[$cur])) {
                continue;
            }
            $visited[$cur] = true;
            $children = DB::table('task_dependencies')
                ->where('task_id', $cur)
                ->pluck('depends_on_task_id')
                ->all();
            foreach ($children as $c) {
                $cid = (int) $c;
                if (in_array($cid, $blockingIds, true)) {
                    return ['ok' => false, 'error' => 'Adding these would create a circular blockage.'];
                }
                $stack[] = $cid;
            }
        }

        DB::transaction(function () use ($task, $blockingIds) {
            DB::table('task_dependencies')->where('depends_on_task_id', $task->id)->delete();
            if (! empty($blockingIds)) {
                $rows = array_map(fn ($id) => [
                    'task_id' => $id,
                    'depends_on_task_id' => $task->id,
                    'created_at' => now(),
                ], $blockingIds);
                DB::table('task_dependencies')->insert($rows);
            }
        });

        return ['ok' => true];
    }

    /**
     * Sync the bidirectional "linked" task list. Pair is stored normalized (a < b).
     */
    public static function syncTaskLinks(TessaTask $task, array $linkIds): array
    {
        $linkIds = array_values(array_unique(array_filter(array_map('intval', $linkIds))));
        $linkIds = array_values(array_filter($linkIds, fn ($id) => $id !== $task->id));

        DB::transaction(function () use ($task, $linkIds) {
            DB::table('task_links')
                ->where('task_a_id', $task->id)
                ->orWhere('task_b_id', $task->id)
                ->delete();

            if (empty($linkIds)) {
                return;
            }

            $rows = [];
            foreach ($linkIds as $other) {
                $a = min($task->id, $other);
                $b = max($task->id, $other);
                $rows[] = [
                    'task_a_id' => $a,
                    'task_b_id' => $b,
                    'created_at' => now(),
                ];
            }
            DB::table('task_links')->insert($rows);
        });

        return ['ok' => true];
    }

    /**
     * Create a task and notify the assigned user via Slack.
     */
    public function createAndNotify(
        User $assignedBy,
        int $assignedToId,
        string $title,
        ?string $description = null,
        string $priority = 'medium',
        ?string $deadline = null,
        bool $isMandatory = false,
        bool $requiresAttachment = false,
        ?string $requiresFormUrl = null
    ): TessaTask {
        $targetUser = User::findOrFail($assignedToId);

        $task = TessaTask::create([
            'assigned_by' => $assignedBy->id,
            'assigned_to' => $assignedToId,
            'title' => $title,
            'description' => $description,
            'priority' => $priority,
            'deadline' => $deadline ? DateHelper::parse($deadline) : null,
            'is_mandatory' => $isMandatory,
            'requires_attachment' => $requiresAttachment,
            'requires_form_url' => $requiresFormUrl,
        ]);

        $this->sendSlackNotification($targetUser, $assignedBy, $task, 'new');

        return $task->fresh();
    }

    /**
     * Get all pending tasks for a user (for sign-in / sign-off reminders).
     */
    public function getPendingTasks(int $userId): Collection
    {
        return TessaTask::where('assigned_to', $userId)
            ->whereIn('status', TessaTask::ACTIVE_STATUSES)
            ->with('assignedBy:id,name')
            ->orderByRaw("FIELD(priority, 'urgent', 'high', 'medium', 'low')")
            ->orderBy('deadline')
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Build a summary of pending tasks for sign-in / sign-off context.
     */
    public function getPendingTasksSummary(int $userId): array
    {
        $tasks = $this->getPendingTasks($userId);

        if ($tasks->isEmpty()) {
            return [
                'count' => 0,
                'tasks' => [],
            ];
        }

        return [
            'count' => $tasks->count(),
            'tasks' => $tasks->map(fn (TessaTask $t) => [
                'id' => $t->id,
                'title' => $t->title,
                'description' => $t->description,
                'priority' => $t->priority,
                'status' => $t->status,
                'assigned_by' => $t->assignedBy?->name,
                'deadline' => $t->deadline?->toIso8601String(),
                'is_overdue' => $t->isOverdue(),
                'created_at' => $t->created_at->toIso8601String(),
            ])->all(),
        ];
    }

    /**
     * Send Slack reminder for all pending tasks on sign-in.
     */
    public function remindOnSignIn(User $user): void
    {
        $tasks = $this->getPendingTasks($user->id);
        if ($tasks->isEmpty()) {
            return;
        }

        $hour = (int) \Carbon\Carbon::now('Asia/Kolkata')->format('H');
        $greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
        $lines = ["*{$greeting} {$user->name}!* You have *{$tasks->count()}* pending task(s):"];
        $lines[] = '';

        foreach ($tasks as $i => $task) {
            $num = $i + 1;
            $overdue = $task->isOverdue() ? ' :warning: *OVERDUE*' : '';
            $deadline = $task->deadline ? ' (Due: ' . $task->deadline->format('D, j M') . ')' : '';
            $lines[] = "{$num}. *{$task->title}*{$deadline}{$overdue}";
            $lines[] = "   _From: {$task->assignedBy?->name}_ | Priority: {$task->priority}";
        }

        $lines[] = '';
        $lines[] = 'Please update or complete these tasks today.';

        $this->sendDm($user, implode("\n", $lines));

        TessaTask::whereIn('id', $tasks->pluck('id'))
            ->update(['reminded_at' => now(), 'remind_count' => \DB::raw('remind_count + 1')]);
    }

    /**
     * Send Slack reminder for pending tasks on sign-off.
     */
    public function remindOnSignOff(User $user): void
    {
        $tasks = $this->getPendingTasks($user->id);
        if ($tasks->isEmpty()) {
            return;
        }

        $lines = ["*Signing off, {$user->name}?* You still have *{$tasks->count()}* pending task(s):"];
        $lines[] = '';

        foreach ($tasks as $i => $task) {
            $num = $i + 1;
            $overdue = $task->isOverdue() ? ' :warning: *OVERDUE*' : '';
            $lines[] = "{$num}. *{$task->title}*{$overdue} — _{$task->assignedBy?->name}_";
        }

        $lines[] = '';
        $lines[] = "Don't forget to update their status before you leave!";

        $this->sendDm($user, implode("\n", $lines));
    }

    /**
     * Slack nudge to a reporter at sign-off time, listing tasks they assigned that
     * are still awaiting their verification. Informational — signoff is NOT blocked.
     */
    public function remindToVerifyOnSignOff(User $reporter): void
    {
        $tasks = $this->getTasksAwaitingVerification($reporter->id);
        if ($tasks->isEmpty()) {
            return;
        }

        $lines = ["*Heads up, {$reporter->name}* — *{$tasks->count()}* task(s) you assigned are awaiting your verification:"];
        $lines[] = '';

        foreach ($tasks as $i => $task) {
            $num = $i + 1;
            $assignee = $task->assignedTo?->name ?? 'someone';
            $lines[] = "{$num}. *{$task->title}* — completed by _{$assignee}_";
        }

        $lines[] = '';
        $lines[] = 'Open the task in Tessa and click *Verify & Close* (or *Reopen* if not satisfied).';

        $this->sendDm($reporter, implode("\n", $lines));
    }

    public function nudge(TessaTask $task, User $nudgedBy): void
    {
        $assignee = $task->assignedTo;
        if (! $assignee) {
            return;
        }

        $deadline = $task->deadline?->format('D, j M') ?? 'No deadline';
        $status = str_replace('_', ' ', $task->status);
        $message = "*Nudge — Please update your task* 👋\n\n"
            . "*{$task->title}*\n"
            . "Status: {$status}\n"
            . "Deadline: {$deadline}\n\n"
            . "_{$nudgedBy->name} is requesting an update._";

        $this->sendDm($assignee, $message);

        $task->increment('remind_count');
    }

    /**
     * Notify the assigner when a task is overdue.
     */
    public function escalateOverdue(TessaTask $task): void
    {
        $assigner = $task->assignedBy;
        $assignee = $task->assignedTo;

        if (! $assigner || ! $assignee) {
            return;
        }

        $deadline = $task->deadline?->format('D, j M') ?? 'N/A';
        $message = "*Task Overdue Alert*\n\n"
            . "*{$task->title}* assigned to *{$assignee->name}* is overdue.\n"
            . "Deadline was: {$deadline}\n"
            . "Status: {$task->status}\n"
            . "Reminded {$task->remind_count} time(s).";

        $this->sendDm($assigner, $message);
    }

    /**
     * Reassign a task to a different user.
     */
    public function reassignTask(TessaTask $task, User $requestedBy, int $newAssigneeId): TessaTask
    {
        $oldAssigneeId = $task->assigned_to;

        if ($oldAssigneeId === $newAssigneeId) {
            return $task;
        }

        $newAssignee = User::findOrFail($newAssigneeId);
        $oldAssignee = User::find($oldAssigneeId);

        // Creator reallocating from scratch — clear any delegator left over from
        // a prior redirect so a stale "shared assigner" doesn't linger.
        $task->update(['assigned_to' => $newAssigneeId, 'shared_assigned_by' => null]);

        // Demote old assignee to 'invited' (preserves thread history)
        TaskParticipant::where('task_id', $task->id)
            ->where('user_id', $oldAssigneeId)
            ->update(['role' => 'invited']);

        // Promote or create new assignee participant
        TaskParticipant::updateOrCreate(
            ['task_id' => $task->id, 'user_id' => $newAssigneeId],
            ['role' => 'assignee']
        );

        // Notify old assignee
        if ($oldAssignee) {
            $this->sendDm($oldAssignee, "*Task Reassigned*\n\n*{$task->title}* has been reassigned from you to *{$newAssignee->name}* by *{$requestedBy->name}*.\nYou still have access to the thread.");
        }

        // Notify new assignee
        $deadlineStr = $task->deadline ? $task->deadline->format('D, j M \a\t g:i A') : 'No deadline';
        $this->sendDm($newAssignee, "*Task Assigned to You*\n\n*{$task->title}*\nPriority: {$task->priority}\nDeadline: {$deadlineStr}\n\n_Reassigned by {$requestedBy->name}_");

        // Notify assigner (if not the one doing the reassignment)
        if ($task->assigned_by !== $requestedBy->id) {
            $assigner = $task->assignedBy;
            if ($assigner) {
                $this->sendDm($assigner, "*Task Reassigned*\n\n*{$task->title}* has been reassigned from *" . ($oldAssignee->name ?? '?') . "* to *{$newAssignee->name}* by *{$requestedBy->name}*.");
            }
        }

        return $task->fresh();
    }

    /**
     * Redirect a task — the current assignee (or the existing shared assigner)
     * passes it on to someone else. Unlike reassignTask (a creator-only
     * reallocation), this keeps `assigned_by` as the original creator and records
     * the person who handed it off as the LATEST shared assigner. The due date
     * carries over by default; pass $newDeadline to change it at redirect time.
     */
    public function redirectTask(TessaTask $task, User $actor, int $newAssigneeId, ?string $newDeadline = null): TessaTask
    {
        $oldAssigneeId = $task->assigned_to;

        if ($oldAssigneeId === $newAssigneeId) {
            return $task;
        }

        $newAssignee = User::findOrFail($newAssigneeId);
        $oldAssignee = User::find($oldAssigneeId);

        $updates = [
            'assigned_to' => $newAssigneeId,
            'shared_assigned_by' => $actor->id,
        ];
        if ($newDeadline) {
            $updates['deadline'] = Carbon::parse($newDeadline);
        }
        $task->update($updates);

        // Demote old assignee to 'invited' (preserves thread history)
        TaskParticipant::where('task_id', $task->id)
            ->where('user_id', $oldAssigneeId)
            ->update(['role' => 'invited']);

        // Promote or create new assignee participant
        TaskParticipant::updateOrCreate(
            ['task_id' => $task->id, 'user_id' => $newAssigneeId],
            ['role' => 'assignee']
        );

        $creator = $task->assignedBy;

        // Notify new assignee
        $deadlineStr = $task->deadline ? $task->deadline->format('D, j M \a\t g:i A') : 'No deadline';
        $origin = ($creator && $creator->id !== $actor->id) ? "\nOriginally assigned by {$creator->name}." : '';
        $this->sendDm($newAssignee, "*Task Redirected to You*\n\n*{$task->title}*\nPriority: {$task->priority}\nDeadline: {$deadlineStr}\n\n_Redirected by {$actor->name}._{$origin}");

        // Notify the person who was holding it (unless they're the one redirecting)
        if ($oldAssignee && $oldAssignee->id !== $actor->id) {
            $this->sendDm($oldAssignee, "*Task Redirected*\n\n*{$task->title}* has been redirected from you to *{$newAssignee->name}* by *{$actor->name}*.\nYou still have access to the thread.");
        }

        // Notify the original creator (unless they did the redirect themselves)
        if ($creator && $creator->id !== $actor->id) {
            $this->sendDm($creator, "*Task Redirected*\n\n*{$task->title}* has been redirected from *" . ($oldAssignee->name ?? '?') . "* to *{$newAssignee->name}* by *{$actor->name}*.");
        }

        return $task->fresh();
    }

    /**
     * Send a Slack DM to a user.
     */
    public function sendDm(User $user, string $message): bool
    {
        $slackUserId = $this->slackService->getUserIdByName($user->name);
        if (! $slackUserId) {
            Log::warning('TessaTaskService: Slack user not found', ['user' => $user->name]);
            return false;
        }

        return $this->slackService->sendDirectMessage($slackUserId, $message);
    }

    /**
     * Notify the assigner when a task status changes (completed, on_hold, cancelled).
     */
    public function notifyStatusChange(TessaTask $task, User $actor, string $newStatus): void
    {
        if ($task->assigned_by === $actor->id) {
            return; // No need to notify yourself
        }

        $assigner = $task->assignedBy;
        if (! $assigner) {
            return;
        }

        $message = match ($newStatus) {
            'completed' => "*Task Completed* :white_check_mark:\n\n*{$actor->name}* marked *{$task->title}* as completed."
                . ($task->status_note ? "\n\nNote: {$task->status_note}" : '')
                . "\n\nPlease verify and close it, or reopen if you're not satisfied with the work.",
            'on_hold' => "*Task On Hold* :pause_button:\n\n*{$task->title}* has been marked as On Hold by *{$actor->name}*."
                . ($task->status_note ? "\n\nNote: {$task->status_note}" : ''),
            'cancelled' => "*Task Cancelled* :no_entry_sign:\n\n*{$task->title}* has been marked as Cancelled by *{$actor->name}*."
                . ($task->status_note ? "\n\nNote: {$task->status_note}" : ''),
            default => null,
        };

        if ($message) {
            $this->sendDm($assigner, $message);
        }
    }

    /**
     * Notify the assignee when the reporter verifies and closes the task.
     */
    public function notifyClosed(TessaTask $task, User $reporter): void
    {
        $assignee = $task->assignedTo;
        if (! $assignee || $assignee->id === $reporter->id) {
            return;
        }

        $message = "*Task Verified & Closed* :white_check_mark:\n\n"
            . "*{$reporter->name}* reviewed and closed *{$task->title}*. Great work!";

        $this->sendDm($assignee, $message);
    }

    /**
     * Notify the assignee when the reporter reopens a previously completed/closed task.
     */
    public function notifyReopened(TessaTask $task, User $reporter, string $reason): void
    {
        $assignee = $task->assignedTo;
        if (! $assignee || $assignee->id === $reporter->id) {
            return;
        }

        $message = "*Task Reopened* :arrows_counterclockwise:\n\n"
            . "*{$reporter->name}* reopened *{$task->title}*.\n\n"
            . "Reason: {$reason}\n\n"
            . 'Please address the feedback and update the task.';

        $this->sendDm($assignee, $message);
    }

    /**
     * Tasks the user has assigned that are awaiting their verification (status = completed).
     */
    public function getTasksAwaitingVerification(int $userId): Collection
    {
        return TessaTask::where('assigned_by', $userId)
            ->where('status', 'completed')
            ->with('assignedTo:id,name')
            ->orderBy('completed_at')
            ->get();
    }

    /**
     * New deadline for an extension of $days.
     *
     * Extensions add days to the *current* deadline, but if the task is
     * already overdue that stale past date stays in the past even after
     * +1–2 days, so the task is stuck "overdue" no matter how many times
     * it's extended/approved. When overdue, anchor to now (keeping the
     * deadline's time-of-day) so the extension grants real runway.
     * One helper => identical behaviour for every assignee/reporter path.
     */
    private function extendedDeadlineFor(TessaTask $task, int $days): Carbon
    {
        $base = $task->deadline->copy();

        if ($base->isPast()) {
            $base = Carbon::now()->setTimeFrom($base);
        }

        return $base->addDays($days);
    }

    public function extendDeadline(TessaTask $task, User $requestedBy, int $days): TessaTask
    {
        $originalDeadline = $task->original_deadline ?? $task->deadline->copy();
        $newDeadline = $this->extendedDeadlineFor($task, $days);

        $task->update([
            'deadline' => $newDeadline,
            'original_deadline' => $originalDeadline,
            'deadline_extension_count' => $task->deadline_extension_count + 1,
            'pending_extension_days' => null,
            'extension_notice_days' => $days,
        ]);

        $assigner = $task->assignedBy;
        if ($assigner && $assigner->id !== $requestedBy->id) {
            $origStr = $originalDeadline->format('D, j M');
            $newStr = $newDeadline->format('D, j M');
            $dayLabel = $days === 1 ? '1 day' : "{$days} days";

            $message = "*Deadline Extended* :calendar:\n\n"
                . "*{$task->title}*\n"
                . "*{$requestedBy->name}* extended the deadline by {$dayLabel}.\n"
                . "Original deadline: {$origStr}\n"
                . "New deadline: {$newStr}";

            $this->sendDm($assigner, $message);
        }

        return $task->fresh();
    }

    public function clearExtensionNotice(TessaTask $task): TessaTask
    {
        $task->update(['extension_notice_days' => null]);

        return $task->fresh();
    }

    public function requestDeadlineExtension(TessaTask $task, User $requestedBy, int $days): void
    {
        $task->update(['pending_extension_days' => $days]);

        $assigner = $task->assignedBy;
        if (! $assigner) {
            return;
        }

        $proposedDeadline = $this->extendedDeadlineFor($task, $days);
        $dayLabel = $days === 1 ? '1 day' : "{$days} days";

        $message = "*Extension Request* :raised_hand:\n\n"
            . "*{$requestedBy->name}* is requesting to extend the deadline for *{$task->title}* by {$dayLabel}.\n"
            . "Current deadline: {$task->deadline->format('D, j M')}\n"
            . "Proposed deadline: {$proposedDeadline->format('D, j M')}\n\n"
            . 'This is extension #' . ($task->deadline_extension_count + 1) . '. Please approve or deny in Tessa.';

        $this->sendDm($assigner, $message);
    }

    public function approveExtension(TessaTask $task, User $approvedBy): TessaTask
    {
        $days = (int) $task->pending_extension_days;
        $originalDeadline = $task->original_deadline ?? $task->deadline->copy();
        $newDeadline = $this->extendedDeadlineFor($task, $days);

        $task->update([
            'deadline' => $newDeadline,
            'original_deadline' => $originalDeadline,
            'deadline_extension_count' => $task->deadline_extension_count + 1,
            'pending_extension_days' => null,
        ]);

        $assignee = $task->assignedTo;
        if ($assignee) {
            $dayLabel = $days === 1 ? '1 day' : "{$days} days";
            $message = "*Extension Approved* :white_check_mark:\n\n"
                . "*{$approvedBy->name}* approved your extension request for *{$task->title}*.\n"
                . "New deadline: {$newDeadline->format('D, j M')}";

            $this->sendDm($assignee, $message);
        }

        return $task->fresh();
    }

    public function denyExtension(TessaTask $task, User $deniedBy): TessaTask
    {
        $task->update(['pending_extension_days' => null]);

        $assignee = $task->assignedTo;
        if ($assignee) {
            $message = "*Extension Denied* :x:\n\n"
                . "*{$deniedBy->name}* denied your extension request for *{$task->title}*.\n"
                . "Current deadline remains: {$task->deadline->format('D, j M')}";

            $this->sendDm($assignee, $message);
        }

        return $task->fresh();
    }

    /**
     * Send notification for a task event.
     */
    private function sendSlackNotification(User $targetUser, User $assignedBy, TessaTask $task, string $event): void
    {
        $deadlineStr = $task->deadline ? $task->deadline->format('D, j M \a\t g:i A') : 'No deadline';

        $message = match ($event) {
            'new' => "*New Task from {$assignedBy->name}*\n\n"
                . "*{$task->title}*\n"
                . ($task->description ? "{$task->description}\n" : '')
                . "Priority: {$task->priority}\n"
                . "Deadline: {$deadlineStr}\n\n"
                . 'Please update this task when done.',
            default => "Task update: *{$task->title}*",
        };

        $this->sendDm($targetUser, $message);
    }
}
