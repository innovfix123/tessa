<?php

namespace App\Services;

use App\Models\Candidate;
use App\Models\ManagerNotification;
use App\Models\ProvisioningRequest;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Account provisioning for a new hire — the "ping Fida + Yuvanesh" step.
 *
 * Single home for the provisioning ticket + the in-app/Slack heads-up so both
 * the Hiring pipeline (HiringController) and the Team → Add Member flow
 * (EmployeeController, candidate mode) share one implementation. Same
 * controller/service split as BillController ↔ BillService: the CALLER owns the
 * actor check; this owns the ticket row + notifications.
 *
 * Provisioner ids come from config('hiring_access.*') — Fida (Tessa login),
 * Yuvanesh (Gmail + Slack).
 */
class ProvisioningService
{
    public function __construct(
        private SlackService $slack,
        private CandidateStageService $stageService,
    ) {}

    /**
     * Open (or refresh) the provisioning ticket for a candidate. Idempotent —
     * keyed on candidate_id. Returns the row so callers can inspect status.
     */
    public function openTicket(Candidate $candidate, ?string $strategy = null): ProvisioningRequest
    {
        return ProvisioningRequest::updateOrCreate(
            ['candidate_id' => $candidate->id],
            [
                'generated_email' => $candidate->generated_email,
                'email_strategy' => $strategy ?: 'custom',
                'tessa_account_user_id' => ((int) config('hiring_access.tessa_provisioner_id')) ?: null,
                'workspace_assignee_id' => ((int) config('hiring_access.workspace_provisioner_id')) ?: null,
                'status' => 'pending',
            ]
        );
    }

    /**
     * Ping the Tessa + workspace provisioners (in-app + Slack). When the Tessa
     * login was already created (the Add-to-Team flow), Fida is asked to VERIFY
     * access rather than create it. Moved verbatim from HiringController.
     */
    public function notify(Candidate $candidate, bool $tessaAutoCreated = false): void
    {
        $name = $candidate->extracted_name ?: 'New hire';
        $email = $candidate->generated_email;
        $url = rtrim((string) config('app.url'), '/') . '/#view=hiring';
        $tessaId = (int) config('hiring_access.tessa_provisioner_id');
        $workspaceId = (int) config('hiring_access.workspace_provisioner_id');

        $tasks = [
            $tessaId => [
                'in_app' => $tessaAutoCreated
                    ? "New hire {$name} ({$email}) — Tessa login auto-created; please verify access."
                    : "New hire {$name} ({$email}) — create the Tessa login.",
                'slack' => $tessaAutoCreated
                    ? "🆕 New hire *{$name}* — *Tessa login auto-created* for *{$email}*; please verify access. <{$url}|Hiring>"
                    : "🆕 New hire *{$name}* — create the *Tessa login* for *{$email}*. <{$url}|Hiring>",
            ],
            $workspaceId => [
                'in_app' => "New hire {$name} ({$email}) — create the Gmail + Slack accounts.",
                'slack' => "🆕 New hire *{$name}* — create the *Gmail + Slack* accounts for *{$email}*. <{$url}|Hiring>",
            ],
        ];

        foreach ($tasks as $uid => $msg) {
            if (! $uid || ! ($u = User::find($uid))) {
                continue;
            }
            ManagerNotification::updateOrCreate(
                [
                    'manager_id' => $uid,
                    'team_member_id' => (int) $candidate->uploaded_by,
                    'source' => 'hiring_provision',
                    'source_ref' => (string) $candidate->id,
                ],
                ['message' => $msg['in_app'], 'dismissed_at' => null]
            );
            try {
                $slackId = $this->slack->getUserIdByName($u->name);
                if ($slackId) {
                    $this->slack->sendDirectMessage($slackId, $msg['slack']);
                }
            } catch (\Throwable $e) {
                Log::warning('ProvisioningService: provisioner Slack DM failed', ['user' => $u->name, 'error' => $e->getMessage()]);
            }
        }
    }

    /**
     * Team → Add Member, candidate mode: the new users row was just created, so
     * link it to the candidate, walk the candidate offer → onboarding, open the
     * provisioning ticket, and ping Fida + Yuvanesh (login already exists, so
     * Fida verifies). Best-effort — a failure here must never undo the hire.
     *
     * Returns the linked candidate, or null when there is nothing to link.
     */
    public function linkHiredCandidate(User $newUser, int $candidateId, User $actor): ?Candidate
    {
        $candidate = Candidate::find($candidateId);
        if (! $candidate || $candidate->hired_user_id) {
            return null;
        }

        $candidate->update([
            'hired_user_id' => $newUser->id,
            'generated_email' => $newUser->email,
        ]);

        // offer → onboarding (the only inbound edge to onboarding). Best-effort:
        // a stale/edge-mismatched stage shouldn't block provisioning.
        try {
            if ($candidate->stage === 'offer') {
                $candidate = $this->stageService->transitionTo($candidate, 'onboarding', $actor);
            }
        } catch (\Throwable $e) {
            Log::warning('ProvisioningService: stage transition skipped', ['candidate' => $candidate->id, 'error' => $e->getMessage()]);
        }

        $this->openTicket($candidate->fresh());
        $this->notify($candidate->fresh(), true);

        return $candidate->fresh();
    }
}
