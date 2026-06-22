<?php

namespace App\Services;

use App\Models\Candidate;
use App\Models\ManagerNotification;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Phase 2 of the re-sequenced hiring flow — auto-detect a candidate's emailed
 * acceptance of their offer/probation letter and flip `offer_accepted_at` so the
 * "Add to Team" CTA surfaces (the actual account creation stays a human step).
 *
 * Targeted, not a full inbox scan: for each candidate still sitting in the
 * `offer` stage we ask each connected HR offer-sender inbox for THAT candidate's
 * own recent replies (`from:<email>`), so the AI only ever sees relevant mail.
 * The reply is classified with gemini-2.5-flash (temp 0 → deterministic across
 * the every-15-min re-runs). Idempotent: once `offer_accepted_at` is set the
 * candidate drops out of the query, so the nudge fires exactly once.
 *
 * Constraint: the portal can't send mail, so the offer goes out via a Gmail
 * compose deep-link from an HR person's own Gmail — the reply lands in THAT
 * inbox. Detection therefore only works for offer-senders who've connected
 * Google (full Gmail scope); unconnected inboxes are skipped (and reported).
 */
class OfferAcceptanceScanner
{
    /** Below this confidence we leave it for HR's manual "Mark accepted". */
    private const MIN_CONFIDENCE = 0.7;

    public function __construct(private SlackService $slack) {}

    /**
     * @return array{candidates:int,inboxes:int,accepted:array,skipped:array,error:?string}
     */
    public function run(bool $dryRun = false, ?int $onlyCandidateId = null): array
    {
        $out = ['candidates' => 0, 'inboxes' => 0, 'accepted' => [], 'skipped' => [], 'error' => null];

        $candidates = Candidate::where('stage', 'offer')
            ->whereNull('offer_accepted_at')
            ->whereNotNull('extracted_email')
            ->when($onlyCandidateId, fn ($q) => $q->where('id', $onlyCandidateId))
            ->get();
        $out['candidates'] = $candidates->count();
        if ($candidates->isEmpty()) {
            return $out;
        }

        // Build one Gmail client per connected offer-sender inbox, up front, so we
        // don't re-instantiate / re-refresh tokens per candidate.
        $senderIds = array_values(array_filter(array_map('intval', (array) config('hiring_access.offer_sender_ids', []))));
        $clients = [];
        foreach (User::whereIn('id', $senderIds)->where('is_active', true)->get() as $hr) {
            if (! $hr->hasGoogleConnection()) {
                $out['skipped'][] = ['inbox' => "#{$hr->id} {$hr->name}", 'reason' => 'Google not connected'];
                continue;
            }
            try {
                $clients[] = ['user' => $hr, 'gmail' => GoogleUserService::forUser($hr)];
            } catch (\Throwable $e) {
                $out['skipped'][] = ['inbox' => "#{$hr->id} {$hr->name}", 'reason' => $e->getMessage()];
            }
        }
        $out['inboxes'] = count($clients);
        if (! $clients) {
            $out['error'] = 'No connected offer-sender inboxes (config hiring_access.offer_sender_ids).';
            return $out;
        }

        foreach ($candidates as $candidate) {
            $email = strtolower(trim((string) $candidate->extracted_email));
            if ($email === '') {
                continue;
            }

            foreach ($clients as $client) {
                $hit = $this->scanInboxFor($candidate, $email, $client['gmail']);
                if (! $hit) {
                    continue;
                }

                $out['accepted'][] = [
                    'candidate_id' => $candidate->id,
                    'name' => $candidate->extracted_name,
                    'email' => $email,
                    'inbox' => "#{$client['user']->id} {$client['user']->name}",
                    'confidence' => $hit['confidence'],
                    'subject' => $hit['subject'],
                ];

                if (! $dryRun) {
                    $candidate->update(['offer_accepted_at' => now(), 'offer_accepted_via' => 'auto']);
                    ActivityLogService::log(
                        $client['user']->id,
                        'hiring.offer_accepted',
                        "Candidate #{$candidate->id} offer accepted (auto, {$hit['confidence']}) — \"" . mb_substr($hit['subject'], 0, 80) . '"',
                        'candidate',
                        $candidate->id
                    );
                    $this->notifyAccepted($candidate, $senderIds);
                }

                break; // one accept per candidate per run
            }
        }

        return $out;
    }

    /**
     * Ask a single inbox for this candidate's recent replies and classify the
     * newest few. Returns the winning verdict (`['confidence','subject']`) or null.
     */
    private function scanInboxFor(Candidate $candidate, string $email, GoogleUserService $gmail): ?array
    {
        try {
            $list = $gmail->listMessages(5, "from:{$email} in:inbox newer_than:21d");
        } catch (\Throwable $e) {
            Log::warning('OfferAcceptanceScanner: Gmail list failed', ['candidate' => $candidate->id, 'error' => $e->getMessage()]);
            return null;
        }
        $ids = array_values(array_filter(array_map(fn ($m) => $m['id'] ?? null, $list['messages'] ?? [])));
        if (! $ids) {
            return null;
        }

        $messages = $gmail->getMessageSnippets(array_slice($ids, 0, 3)); // newest first
        foreach ($messages as $msg) {
            // Strict sender guard — Gmail's `from:` is fuzzy; require an exact match.
            if ($this->extractSenderEmail((string) ($msg['from'] ?? '')) !== $email) {
                continue;
            }
            $verdict = $this->classify($candidate, $msg);
            if ($verdict['accepted'] && $verdict['confidence'] >= self::MIN_CONFIDENCE) {
                return ['confidence' => $verdict['confidence'], 'subject' => (string) ($msg['subject'] ?? '(no subject)')];
            }
        }

        return null;
    }

    /** gemini-2.5-flash, temperature 0 → deterministic accept/not verdict. */
    private function classify(Candidate $candidate, array $msg): array
    {
        $system = <<<'PROMPT'
You are an offer-acceptance classifier for a recruiting team. You are given ONE email
a job candidate sent in reply to a job / probation offer letter. Decide whether the
candidate is ACCEPTING the offer.

Return ONLY a JSON object — no prose, no markdown fences:
{"accepted": true|false, "confidence": 0.0-1.0}

Rules:
- accepted=true ONLY for a clear affirmative acceptance / confirmation of joining
  (e.g. "I accept", "happy to accept", "I confirm my joining", "I'm joining",
  "looking forward to starting", a signed acceptance).
- accepted=false for questions, salary/date negotiation, "let me think", requests for
  more time, document-only replies, declines, auto-replies, or anything ambiguous.
- confidence: 0.9+ for an explicit acceptance, ~0.5 when unclear.
PROMPT;

        $user = "Candidate: " . (string) ($candidate->extracted_name ?: 'Unknown')
            . "\nFrom: " . (string) ($msg['from'] ?? '')
            . "\nSubject: " . (string) ($msg['subject'] ?? '')
            . "\nBody preview: " . mb_substr((string) ($msg['snippet'] ?? ''), 0, 600);

        try {
            $raw = app(TessaAIService::class)->quickAi($system, $user, 0.0);
        } catch (\Throwable $e) {
            Log::warning('OfferAcceptanceScanner: classify failed', ['candidate' => $candidate->id, 'error' => $e->getMessage()]);
            return ['accepted' => false, 'confidence' => 0.0];
        }

        $obj = $this->parseJsonObject($raw);
        $conf = $obj['confidence'] ?? 0;
        $conf = is_numeric($conf) ? max(0.0, min(1.0, (float) $conf)) : 0.0;

        return [
            'accepted' => filter_var($obj['accepted'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'confidence' => $conf,
        ];
    }

    /** Decode a JSON object from an AI response, tolerating fences / stray prose. */
    private function parseJsonObject(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        $raw = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $raw);
        if (! str_starts_with(ltrim($raw), '{')) {
            if (preg_match('/\{.*\}/s', $raw, $m)) {
                $raw = $m[0];
            }
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** Extract the bare email address from a raw `From` header, lowercased. */
    private function extractSenderEmail(string $from): string
    {
        if (preg_match('/<([^>]+)>/', $from, $m)) {
            return strtolower(trim($m[1]));
        }

        return strtolower(trim($from));
    }

    /**
     * Ping the HR offer-senders that a candidate accepted — dashboard card + Slack
     * DM — so they go add the hire to the team. Mirrors ProvisioningService::notify.
     */
    private function notifyAccepted(Candidate $candidate, array $hrIds): void
    {
        $name = $candidate->extracted_name ?: 'A candidate';
        $url = rtrim((string) config('app.url'), '/') . '/#view=hiring';
        $inApp = "{$name} accepted the offer (detected from Gmail) — add them to the team.";
        $slackMsg = "✅ *{$name}* accepted the offer (detected from Gmail) — *add them to the team*. <{$url}|Hiring>";

        foreach (array_unique($hrIds) as $uid) {
            if (! $uid || ! ($u = User::find($uid))) {
                continue;
            }
            ManagerNotification::updateOrCreate(
                [
                    'manager_id' => (int) $uid,
                    'team_member_id' => (int) $candidate->uploaded_by,
                    'source' => 'hiring_offer_accepted',
                    'source_ref' => (string) $candidate->id,
                ],
                ['message' => $inApp, 'dismissed_at' => null]
            );
            try {
                $slackId = $this->slack->getUserIdByName($u->name);
                if ($slackId) {
                    $this->slack->sendDirectMessage($slackId, $slackMsg);
                }
            } catch (\Throwable $e) {
                Log::warning('OfferAcceptanceScanner: accept Slack DM failed', ['user' => $u->name, 'error' => $e->getMessage()]);
            }
        }
    }
}
