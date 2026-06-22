<?php

namespace App\Services;

use App\Models\RewardPool;
use App\Models\RewardTask;
use App\Models\RewardTaskUpdate;
use App\Models\RewardWithdrawal;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RewardService
{
    public function __construct(
        private SlackService $slackService
    ) {}

    public function assignTask(User $assigner, array $data): RewardTask
    {
        $title = trim($data['title'] ?? '');
        $assigneeId = (int) ($data['assigned_to_id'] ?? 0);
        $amount = (float) ($data['amount'] ?? 0);

        if ($title === '') {
            throw new \InvalidArgumentException('Title is required.');
        }
        if ($assigneeId <= 0) {
            throw new \InvalidArgumentException('Assignee is required.');
        }
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Reward amount must be greater than zero.');
        }
        $assignee = User::where('is_active', true)->find($assigneeId);
        if (! $assignee) {
            throw new \InvalidArgumentException('Assignee not found or inactive.');
        }

        $task = RewardTask::create([
            'assigned_to_id' => $assigneeId,
            'assigned_by_id' => $assigner->id,
            'title' => $title,
            'description' => trim($data['description'] ?? '') ?: null,
            'amount' => $amount,
            'deadline' => $data['deadline'] ?? null,
            'status' => 'assigned',
        ]);

        $this->notifyAssigneeOfNewTask($task);

        Log::info('RewardService: task assigned', [
            'task_id' => $task->id,
            'assigned_to' => $assigneeId,
            'assigned_by' => $assigner->id,
            'amount' => $amount,
        ]);

        return $task->fresh(['assignee', 'assigner']);
    }

    public function updateTask(User $assigner, RewardTask $task, array $data): RewardTask
    {
        if ($task->isLocked()) {
            throw new \InvalidArgumentException('Cannot edit a task that has already been reviewed.');
        }

        $update = [];
        if (array_key_exists('title', $data)) {
            $title = trim((string) $data['title']);
            if ($title === '') {
                throw new \InvalidArgumentException('Title cannot be empty.');
            }
            $update['title'] = $title;
        }
        if (array_key_exists('description', $data)) {
            $update['description'] = trim((string) $data['description']) ?: null;
        }
        if (array_key_exists('amount', $data)) {
            $amount = (float) $data['amount'];
            if ($amount <= 0) {
                throw new \InvalidArgumentException('Amount must be greater than zero.');
            }
            $update['amount'] = $amount;
        }
        if (array_key_exists('deadline', $data)) {
            $update['deadline'] = $data['deadline'] ?: null;
        }

        if (! empty($update)) {
            $task->update($update);
        }

        return $task->fresh(['assignee', 'assigner']);
    }

    public function addUpdate(User $user, RewardTask $task, array $data): RewardTaskUpdate
    {
        if ($task->assigned_to_id !== $user->id) {
            throw new \InvalidArgumentException('Only the assignee can post progress updates.');
        }
        if (!in_array($task->status, ['assigned', 'submitted'], true)) {
            throw new \InvalidArgumentException('Progress updates are only allowed while the task is in progress.');
        }
        $note = trim($data['note'] ?? '');
        if ($note === '') {
            throw new \InvalidArgumentException('Update note is required.');
        }

        return RewardTaskUpdate::create([
            'reward_task_id' => $task->id,
            'user_id' => $user->id,
            'note' => $note,
            'evidence_url' => trim($data['evidence_url'] ?? '') ?: null,
        ]);
    }

    public function submitTask(User $user, RewardTask $task, array $data): RewardTask
    {
        if ($task->assigned_to_id !== $user->id) {
            throw new \InvalidArgumentException('Only the assignee can mark this task as done.');
        }
        if ($task->status !== 'assigned') {
            throw new \InvalidArgumentException('This task has already been submitted or reviewed.');
        }

        $task->update([
            'status' => 'submitted',
            'submission_note' => trim($data['note'] ?? '') ?: null,
            'submission_evidence_url' => trim($data['evidence_url'] ?? '') ?: null,
            'submitted_at' => now(),
        ]);

        $this->notifyReviewersOfSubmission($task);

        Log::info('RewardService: task submitted', [
            'task_id' => $task->id,
            'user_id' => $user->id,
        ]);

        return $task->fresh(['assignee', 'assigner']);
    }

    public function approveTask(User $reviewer, RewardTask $task, ?float $finalAmount, ?string $note): RewardTask
    {
        if ($task->isLocked()) {
            throw new \InvalidArgumentException('This task has already been reviewed.');
        }

        $amount = $finalAmount !== null ? round((float) $finalAmount, 2) : (float) $task->amount;
        if ($amount < 0) {
            throw new \InvalidArgumentException('Final amount cannot be negative.');
        }
        if ($amount > (float) $task->amount) {
            throw new \InvalidArgumentException('Final amount cannot exceed the originally offered amount.');
        }

        return DB::transaction(function () use ($reviewer, $task, $amount, $note) {
            $task->update([
                'status' => 'approved',
                'final_amount' => $amount,
                'reviewed_at' => now(),
                'reviewed_by_id' => $reviewer->id,
                'review_note' => $note ?: null,
            ]);

            if ($amount > 0) {
                RewardWithdrawal::create([
                    'user_id' => $task->assigned_to_id,
                    'reward_task_id' => $task->id,
                    'amount' => $amount,
                    'status' => 'pending',
                    'requested_at' => now(),
                ]);
                $this->notifyPayersOfNewWithdrawal($task, $amount);
            }

            $this->notifyAssigneeOfApproval($task->fresh(), $amount);

            Log::info('RewardService: task approved', [
                'task_id' => $task->id,
                'reviewer_id' => $reviewer->id,
                'final_amount' => $amount,
            ]);

            return $task->fresh(['assignee', 'assigner', 'reviewer', 'withdrawal']);
        });
    }

    public function rejectTask(User $reviewer, RewardTask $task, string $reason): RewardTask
    {
        if ($task->isLocked()) {
            throw new \InvalidArgumentException('This task has already been reviewed.');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new \InvalidArgumentException('Rejection reason is required.');
        }

        $task->update([
            'status' => 'rejected',
            'final_amount' => 0,
            'reviewed_at' => now(),
            'reviewed_by_id' => $reviewer->id,
            'review_note' => $reason,
        ]);

        $this->notifyAssigneeOfRejection($task->fresh(), $reason);

        Log::info('RewardService: task rejected', [
            'task_id' => $task->id,
            'reviewer_id' => $reviewer->id,
        ]);

        return $task->fresh(['assignee', 'assigner', 'reviewer']);
    }

    public function markPaid(User $payer, RewardWithdrawal $withdrawal, ?string $utr = null, ?string $note = null): RewardWithdrawal
    {
        if ($withdrawal->status !== 'pending') {
            throw new \InvalidArgumentException('Only pending withdrawals can be marked paid.');
        }

        $withdrawal->update([
            'status' => 'paid',
            'paid_at' => now(),
            'paid_by' => $payer->id,
            'utr_number' => $utr,
            'admin_note' => $note,
        ]);

        $this->notifyEmployeeOfPayment($withdrawal);

        Log::info('RewardService: withdrawal paid', [
            'withdrawal_id' => $withdrawal->id,
            'payer_id' => $payer->id,
        ]);

        return $withdrawal->fresh(['user', 'paidBy', 'rewardTask']);
    }

    // ─── Reward Pools ─────────────────────────────────────────────────────────
    // A pool creator (config 'pool_creators' — Krishnan) logs a team reward pool
    // (title + description + amount) that goes straight to the payer's queue. No
    // assignee, no approval — direct creator → payer payout.

    public function isPoolCreator(User $user): bool
    {
        return in_array((int) $user->id, array_map('intval', (array) config('rewards.pool_creators', [])), true);
    }

    public function createRewardPool(User $creator, array $data): RewardPool
    {
        $title = trim($data['title'] ?? '');
        $amount = (float) ($data['amount'] ?? 0);
        if ($title === '') {
            throw new \InvalidArgumentException('Title is required.');
        }
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Reward amount must be greater than zero.');
        }

        $pool = RewardPool::create([
            'created_by' => $creator->id,
            'title' => $title,
            'description' => trim($data['description'] ?? '') ?: null,
            'amount' => $amount,
            'status' => 'pending',
        ]);

        $this->notifyPayersOfNewPool($pool->fresh('creator'));

        Log::info('RewardService: reward pool created', [
            'pool_id' => $pool->id,
            'created_by' => $creator->id,
            'amount' => $amount,
        ]);

        return $pool->fresh('creator');
    }

    public function markPoolPaid(User $payer, RewardPool $pool, ?string $utr = null, ?string $note = null): RewardPool
    {
        if ($pool->status !== 'pending') {
            throw new \InvalidArgumentException('Only pending reward pools can be marked paid.');
        }

        $pool->update([
            'status' => 'paid',
            'paid_at' => now(),
            'paid_by' => $payer->id,
            'utr_number' => $utr,
            'admin_note' => $note,
        ]);

        $this->notifyCreatorOfPoolPaid($pool->fresh(['creator', 'paidBy']));

        Log::info('RewardService: reward pool paid', [
            'pool_id' => $pool->id,
            'payer_id' => $payer->id,
        ]);

        return $pool->fresh(['creator', 'paidBy']);
    }

    /**
     * Wallet snapshot.
     * - earned_total: sum of final_amount on approved tasks
     * - awaiting_payment: pending withdrawals
     * - paid_total: paid withdrawals
     */
    public function walletBalance(User $user): array
    {
        $earned = (float) RewardTask::query()
            ->where('assigned_to_id', $user->id)
            ->where('status', 'approved')
            ->sum('final_amount');

        $awaiting = (float) RewardWithdrawal::query()
            ->where('user_id', $user->id)
            ->where('status', 'pending')
            ->sum('amount');

        $paid = (float) RewardWithdrawal::query()
            ->where('user_id', $user->id)
            ->where('status', 'paid')
            ->sum('amount');

        return [
            'earned_total' => round($earned, 2),
            'awaiting_payment' => round($awaiting, 2),
            'paid_total' => round($paid, 2),
        ];
    }

    // ─── Slack helpers ────────────────────────────────────────────────────────

    private function sendSlackDm(?string $userName, string $message): void
    {
        if (! $userName) return;
        try {
            $slackId = $this->slackService->getUserIdByName($userName);
            if ($slackId) {
                $this->slackService->sendDirectMessage($slackId, $message);
            } else {
                Log::warning('RewardService: Could not resolve Slack user', ['name' => $userName]);
            }
        } catch (\Throwable $e) {
            Log::error('RewardService: Slack DM failed', ['name' => $userName, 'error' => $e->getMessage()]);
        }
    }

    private function rewardsPortalUrl(): string
    {
        return rtrim(config('app.url'), '/') . '/#view=rewards';
    }

    private function fmtInr(float $amount): string
    {
        return '₹' . number_format($amount, 2);
    }

    private function notifyAssigneeOfNewTask(RewardTask $task): void
    {
        $deadline = $task->deadline ? ' by ' . $task->deadline->format('M j, Y') : '';
        $msg = "🎯 You have a new reward task: *{$task->title}* — "
            . $this->fmtInr((float) $task->amount) . $deadline . ".\n"
            . ($task->description ? "> " . $this->snippet($task->description) . "\n" : '')
            . "Open it on Tessa: <{$this->rewardsPortalUrl()}|Rewards>";
        $this->sendSlackDm($task->assignee->name ?? null, $msg);
    }

    private function notifyReviewersOfSubmission(RewardTask $task): void
    {
        $reviewers = User::whereIn('id', config('rewards.reviewers', []))->get();
        $assigneeName = $task->assignee->name ?? 'Someone';
        $overdueBadge = $task->isOverdue() ? ' ⚠️ overdue' : '';
        $msg = "📤 *{$assigneeName}* submitted reward task *{$task->title}*{$overdueBadge}.\n"
            . ($task->submission_note ? "> " . $this->snippet($task->submission_note) . "\n" : '')
            . "Review on Tessa: <{$this->rewardsPortalUrl()}|Rewards>";
        foreach ($reviewers as $r) {
            $this->sendSlackDm($r->name, $msg);
        }
    }

    private function notifyAssigneeOfApproval(RewardTask $task, float $finalAmount): void
    {
        $original = (float) $task->amount;
        if ($finalAmount <= 0) {
            $msg = "Your reward task *{$task->title}* was reviewed — no reward this time.";
            if ($task->review_note) {
                $msg .= "\n> {$task->review_note}";
            }
        } elseif ($finalAmount < $original) {
            $msg = "🎉 Your reward task *{$task->title}* was approved at " . $this->fmtInr($finalAmount)
                . " (original: " . $this->fmtInr($original) . ").";
            if ($task->review_note) {
                $msg .= "\n> {$task->review_note}";
            }
        } else {
            $msg = "🎉 Your reward task *{$task->title}* was approved — " . $this->fmtInr($finalAmount) . " added to your wallet.";
        }
        $msg .= "\nOpen Tessa: <{$this->rewardsPortalUrl()}|Rewards>";
        $this->sendSlackDm($task->assignee->name ?? null, $msg);
    }

    private function notifyPayersOfNewWithdrawal(RewardTask $task, float $amount): void
    {
        $heads = User::whereIn('id', config('rewards.approval_heads_up', []))->get();
        $assigneeName = $task->assignee->name ?? 'an employee';
        $msg = "💸 New payout pending: " . $this->fmtInr($amount) . " for *{$assigneeName}* "
            . "(*{$task->title}*). It is now in your Pay queue.";
        foreach ($heads as $u) {
            $this->sendSlackDm($u->name, $msg);
        }
    }

    private function notifyAssigneeOfRejection(RewardTask $task, string $reason): void
    {
        $msg = "Your reward task *{$task->title}* was not approved.\n"
            . "Reason: {$reason}";
        $this->sendSlackDm($task->assignee->name ?? null, $msg);
    }

    private function notifyEmployeeOfPayment(RewardWithdrawal $withdrawal): void
    {
        $payerName = $withdrawal->paidBy->name ?? 'Finance';
        $taskTitle = $withdrawal->rewardTask?->title;
        $msg = "✅ " . $this->fmtInr((float) $withdrawal->amount) . " paid by {$payerName}"
            . ($withdrawal->utr_number ? " (UTR: {$withdrawal->utr_number})" : '')
            . ($taskTitle ? " for *{$taskTitle}*" : '')
            . ".";
        $this->sendSlackDm($withdrawal->user->name ?? null, $msg);
    }

    private function notifyPayersOfNewPool(RewardPool $pool): void
    {
        // Mirrors notifyPayersOfNewWithdrawal — DMs the payout heads-up list (Ayush).
        $heads = User::whereIn('id', array_map('intval', (array) config('rewards.approval_heads_up', [])))->get();
        $creatorName = $pool->creator->name ?? 'A manager';
        $msg = "🏆 *{$creatorName}* sent a team reward pool to your Pay queue: *{$pool->title}* — "
            . $this->fmtInr((float) $pool->amount) . ".\n"
            . ($pool->description ? "> " . $this->snippet($pool->description) . "\n" : '')
            . "Settle it on Tessa: <{$this->rewardsPortalUrl()}|Rewards>";
        foreach ($heads as $u) {
            $this->sendSlackDm($u->name, $msg);
        }
    }

    private function notifyCreatorOfPoolPaid(RewardPool $pool): void
    {
        $payerName = $pool->paidBy->name ?? 'Finance';
        $msg = "✅ Your team reward pool *{$pool->title}* (" . $this->fmtInr((float) $pool->amount) . ") was paid by {$payerName}"
            . ($pool->utr_number ? " (UTR: {$pool->utr_number})" : '')
            . ".";
        $this->sendSlackDm($pool->creator->name ?? null, $msg);
    }

    private function snippet(string $text, int $length = 180): string
    {
        $clean = trim(preg_replace('/\s+/', ' ', $text));
        return mb_strlen($clean) > $length ? mb_substr($clean, 0, $length) . '…' : $clean;
    }
}
