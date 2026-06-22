<?php

namespace App\Services;

use App\Models\Candidate;
use App\Models\User;

/**
 * The candidate state machine. Validates the stage EDGE (the graph) and stamps
 * the relevant columns + an audit log. The CONTROLLER validates the ACTOR
 * (who may approve/reject) before calling here, and owns notifications — same
 * split as BillController / BillService.
 *
 * Forward-only except reject/withdraw, which are allowed from any non-terminal
 * stage. The full graph is declared (all phases) so later phases just call it.
 */
class CandidateStageService
{
    /** Allowed forward edges. */
    private const FORWARD = [
        'sourced' => ['panel_review', 'tech_round'],
        'panel_review' => ['tech_round'],
        'tech_round' => ['hr_round'],
        'hr_round' => ['accepted'],
        'accepted' => ['provisioning'],
        'provisioning' => ['offer'],
        'offer' => ['onboarding'],
        'onboarding' => ['hired'],
    ];

    private const TERMINAL = ['hired', 'rejected', 'withdrawn'];

    /**
     * @param  array  $opts  ['reason' => string] for rejections
     * @throws \InvalidArgumentException on a disallowed edge
     */
    public function transitionTo(Candidate $candidate, string $to, User $actor, array $opts = []): Candidate
    {
        $from = $candidate->stage;
        if ($from === $to) {
            return $candidate;
        }
        $this->assertAllowed($from, $to);

        $data = ['stage' => $to];
        if ($to === 'tech_round') {
            $data['approved_by'] = $actor->id;
        }
        if ($to === 'rejected') {
            $data['rejected_by'] = $actor->id;
            $reason = trim((string) ($opts['reason'] ?? ''));
            if ($reason === '') {
                throw new \InvalidArgumentException('A rejection reason is required.');
            }
            $data['rejected_reason'] = $reason;
        }

        $candidate->update($data);

        ActivityLogService::log(
            $actor->id,
            'hiring.candidate_stage',
            "Candidate #{$candidate->id} moved {$from} → {$to}",
            'candidate',
            $candidate->id,
            ['from' => $from, 'to' => $to]
        );

        return $candidate->fresh();
    }

    private function assertAllowed(string $from, string $to): void
    {
        if (in_array($from, self::TERMINAL, true)) {
            throw new \InvalidArgumentException("This candidate is already {$from} and can't be changed.");
        }
        // Reject / withdraw are reachable from any non-terminal stage.
        if ($to === 'rejected' || $to === 'withdrawn') {
            return;
        }
        if (! in_array($to, self::FORWARD[$from] ?? [], true)) {
            throw new \InvalidArgumentException("Can't move a candidate from {$from} to {$to}.");
        }
    }
}
