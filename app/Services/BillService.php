<?php

namespace App\Services;

use App\Models\Bill;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Bills & Reimbursements flow. Mirrors RewardService: the controller handles
 * validation + file storage, this service owns the DB transition and the
 * Slack notifications (resolve-by-name DMs, same as RewardService).
 *
 * Admins (config bills_access.admin_user_ids) settle requests; a payer can
 * never mark their OWN request paid — the other admin does.
 */
class BillService
{
    public function __construct(
        private SlackService $slackService
    ) {}

    /**
     * Create a new request. $data: type, title, description, category, amount,
     * currency, vendor_name, sheet_url, file_path, file_name, file_size, files.
     * Travel rows are link-only (sheet_url set, no files, amount 0 until paid).
     */
    public function submit(User $user, array $data): Bill
    {
        $type = $data['type'] ?? '';
        if (! in_array($type, ['bill', 'reimbursement', 'travel'], true)) {
            throw new \InvalidArgumentException('Invalid request type.');
        }
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            throw new \InvalidArgumentException('Title is required.');
        }
        $amount = round((float) ($data['amount'] ?? 0), 2);
        // Travel rows are link-only: no amount at submit (the admin sets it when
        // they pay). Every other type still needs a positive amount up front.
        if ($amount <= 0 && $type !== 'travel') {
            throw new \InvalidArgumentException('Amount must be greater than zero.');
        }

        $bill = Bill::create([
            'user_id' => $user->id,
            'type' => $type,
            'title' => $title,
            'description' => trim((string) ($data['description'] ?? '')) ?: null,
            'category' => trim((string) ($data['category'] ?? '')) ?: null,
            'amount' => $amount,
            'currency' => trim((string) ($data['currency'] ?? '')) ?: 'INR',
            'vendor_name' => trim((string) ($data['vendor_name'] ?? '')) ?: null,
            'sheet_url' => trim((string) ($data['sheet_url'] ?? '')) ?: null,
            'file_path' => $data['file_path'] ?? null,
            'file_name' => $data['file_name'] ?? null,
            'file_size' => $data['file_size'] ?? null,
            'files' => $data['files'] ?? null,
            'status' => 'pending',
        ]);

        $this->notifyAdminsOfNewRequest($bill->fresh('submitter'));

        Log::info('BillService: request submitted', [
            'bill_id' => $bill->id,
            'user_id' => $user->id,
            'type' => $type,
            'amount' => $amount,
        ]);

        return $bill->fresh(['submitter', 'reviewer']);
    }

    public function markPaid(
        User $admin,
        Bill $bill,
        ?string $transactionId,
        ?string $proofPath,
        ?string $proofName,
        ?string $note,
        ?float $amount = null
    ): Bill {
        if ($bill->status !== 'pending') {
            throw new \InvalidArgumentException('Only pending requests can be marked paid.');
        }
        if ((int) $bill->user_id === (int) $admin->id) {
            throw new \InvalidArgumentException('You cannot pay your own request — the other admin settles it.');
        }
        if (! $transactionId && ! $proofPath) {
            throw new \InvalidArgumentException('Add a transaction ID or attach a payment screenshot as proof.');
        }

        $updates = [
            'status' => 'paid',
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
            'transaction_id' => $transactionId,
            'proof_path' => $proofPath,
            'proof_name' => $proofName,
            'payment_note' => $note,
        ];
        // Travel rows had no amount at submit — the admin enters it now.
        if ($amount !== null && $amount > 0) {
            $updates['amount'] = round($amount, 2);
        }
        $bill->update($updates);

        $this->notifySubmitterOfPayment($bill->fresh(['submitter', 'reviewer']));

        Log::info('BillService: request paid', [
            'bill_id' => $bill->id,
            'admin_id' => $admin->id,
        ]);

        return $bill->fresh(['submitter', 'reviewer']);
    }

    public function reject(User $admin, Bill $bill, string $reason): Bill
    {
        if ($bill->status !== 'pending') {
            throw new \InvalidArgumentException('Only pending requests can be rejected.');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new \InvalidArgumentException('Rejection reason is required.');
        }

        $bill->update([
            'status' => 'rejected',
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
            'rejection_reason' => $reason,
        ]);

        $this->notifySubmitterOfRejection($bill->fresh(['submitter', 'reviewer']));

        Log::info('BillService: request rejected', [
            'bill_id' => $bill->id,
            'admin_id' => $admin->id,
        ]);

        return $bill->fresh(['submitter', 'reviewer']);
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
                Log::warning('BillService: Could not resolve Slack user', ['name' => $userName]);
            }
        } catch (\Throwable $e) {
            Log::error('BillService: Slack DM failed', ['name' => $userName, 'error' => $e->getMessage()]);
        }
    }

    private function billsPortalUrl(): string
    {
        return rtrim(config('app.url'), '/') . '/#view=bills';
    }

    private function fmtInr(float $amount): string
    {
        return '₹' . number_format($amount, 2);
    }

    private function typeLabel(Bill $bill): string
    {
        return match ($bill->type) {
            'reimbursement' => 'reimbursement',
            'travel' => 'travel',
            default => 'bill',
        };
    }

    private function notifyAdminsOfNewRequest(Bill $bill): void
    {
        $admins = User::whereIn('id', config('bills_access.approval_heads_up', []))
            ->where('id', '!=', $bill->user_id) // don't DM the submitter their own request
            ->get();
        $who = $bill->submitter->name ?? 'an employee';

        if ($bill->type === 'travel') {
            // Travel rows carry no amount at submit — point the admins at the
            // sheet and remind them to set the amount when they pay it (no "₹0").
            $link = $bill->sheet_url ? "<{$bill->sheet_url}|travel sheet>" : 'travel sheet';
            $msg = "🧾 New {$link} from *{$who}* — *{$bill->title}*. "
                . "Set the amount when you pay it: <{$this->billsPortalUrl()}|Bills>";
        } else {
            $msg = "🧾 New {$this->typeLabel($bill)} request: " . $this->fmtInr((float) $bill->amount)
                . " from *{$who}* — *{$bill->title}*. It's in your Bills queue: "
                . "<{$this->billsPortalUrl()}|Bills>";
        }

        foreach ($admins as $u) {
            $this->sendSlackDm($u->name, $msg);
        }
    }

    private function notifySubmitterOfPayment(Bill $bill): void
    {
        $payer = $bill->reviewer->name ?? 'Finance';
        $msg = "✅ " . $this->fmtInr((float) $bill->amount) . " paid by {$payer}"
            . ($bill->transaction_id ? " (txn: {$bill->transaction_id})" : '')
            . " for your {$this->typeLabel($bill)} *{$bill->title}*."
            . ($bill->payment_note ? "\n> {$bill->payment_note}" : '');
        $this->sendSlackDm($bill->submitter->name ?? null, $msg);
    }

    private function notifySubmitterOfRejection(Bill $bill): void
    {
        $msg = "Your {$this->typeLabel($bill)} request *{$bill->title}* "
            . "(" . $this->fmtInr((float) $bill->amount) . ") was not approved.\n"
            . "Reason: {$bill->rejection_reason}";
        $this->sendSlackDm($bill->submitter->name ?? null, $msg);
    }
}
