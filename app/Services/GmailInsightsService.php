<?php

namespace App\Services;

use App\Models\GmailInsight;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Pulls a user's recent Gmail messages, AI-classifies them as important work
 * notifications (vs ads/newsletters), and persists the important ones to
 * `gmail_insights` for the dashboard Gmail tab. Mirrors SlackInsightsService,
 * but personal-only — every email belongs solely to its inbox owner.
 */
class GmailInsightsService
{
    private const ALLOWED_PRIORITIES = ['low', 'medium', 'high', 'urgent'];

    /** The per-user relevance filter from config, or null if the user has none. */
    public static function filterFor(int $userId): ?array
    {
        $filters = (array) config('gmail_insights.user_filters', []);

        return $filters[$userId] ?? null;
    }

    /**
     * Company domain a user's Gmail tab is restricted to (internal-only mode),
     * or null. In this mode the AI importance gate is bypassed entirely: every
     * inbox email from an @{domain} sender is surfaced, and nothing else.
     */
    public static function internalDomainFor(int $userId): ?string
    {
        $map    = (array) config('gmail_insights.internal_only_domains', []);
        $domain = strtolower(trim((string) ($map[$userId] ?? '')));

        return $domain !== '' ? $domain : null;
    }

    /**
     * Name tokens to match for a "mention-only" recipient — their Gmail tab shows
     * ONLY emails mentioning their own name, with the AI importance gate bypassed.
     * Tokens are the user's full name and its first word (lowercased), taken from
     * users.name. Returns [] if the user isn't in this mode (or has no name).
     *
     * @return string[]
     */
    public static function mentionNamesFor(int $userId): array
    {
        $ids = array_map('intval', (array) config('gmail_insights.mention_only_user_ids', []));
        if (! in_array($userId, $ids, true)) {
            return [];
        }

        $name = strtolower(trim((string) (User::whereKey($userId)->value('name') ?? '')));
        if ($name === '') {
            return [];
        }

        $first = trim(explode(' ', $name)[0] ?? '');

        return array_values(array_unique(array_filter([$name, $first])));
    }

    /**
     * Extra AI categories a mention-focus recipient also wants surfaced even when
     * the email doesn't mention their name (e.g. Meeting/Calendar). [] if none.
     *
     * @return string[]
     */
    public static function mentionPlusCategoriesFor(int $userId): array
    {
        $map = (array) config('gmail_insights.mention_plus_categories', []);

        return array_values(array_filter(array_map(
            fn ($c) => trim((string) $c),
            (array) ($map[$userId] ?? [])
        )));
    }

    /**
     * True if the email's subject or snippet mentions one of the given names
     * (word-boundary, case-insensitive). Scans subject+snippet ONLY — never the
     * raw From/To headers — so the user's own address can't false-match.
     *
     * @param  string[]  $names
     */
    private function mentionsName(array $msg, array $names): bool
    {
        $hay = strtolower((string) ($msg['subject'] ?? '') . ' ' . (string) ($msg['snippet'] ?? ''));
        foreach ($names as $name) {
            if ($name !== '' && preg_match('/\b' . preg_quote($name, '/') . '\b/u', $hay)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Internal "force" senders for a user, resolved to lowercased email
     * substrings. These bypass the AI importance gate at sync time, so every
     * email from them is stored.
     *
     * @return string[]
     */
    public static function forceSenderPatternsFor(int $userId): array
    {
        $f = self::filterFor($userId);
        if (! $f) {
            return [];
        }

        $ids = array_filter(array_map('intval', (array) ($f['force_sender_user_ids'] ?? [])));
        if (! $ids) {
            return [];
        }

        return User::whereIn('id', $ids)
            ->pluck('email')
            ->map(fn ($e) => strtolower(trim((string) $e)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * All sender substrings to MATCH at read time = the internal force-sender
     * emails plus the raw external `sender_patterns`.
     *
     * @return string[]
     */
    public static function readSenderPatternsFor(int $userId): array
    {
        $f = self::filterFor($userId);
        if (! $f) {
            return [];
        }

        $external = array_map(
            fn ($p) => strtolower(trim((string) $p)),
            (array) ($f['sender_patterns'] ?? [])
        );

        return array_values(array_unique(array_filter(
            array_merge(self::forceSenderPatternsFor($userId), $external)
        )));
    }

    /**
     * Fetch + classify + persist important emails for one user.
     *
     * @return array{created:int, fetched:int, scanned:int, important:array<int,array>, error:?string}
     */
    public function syncForUser(User $user, bool $dryRun = false): array
    {
        $out = ['created' => 0, 'fetched' => 0, 'scanned' => 0, 'important' => [], 'error' => null];

        if (! $user->hasGoogleConnection()) {
            $out['error'] = 'not connected';
            return $out;
        }

        try {
            $gmail = GoogleUserService::forUser($user);

            // Internal-only recipients (e.g. Fida): restrict the Gmail search to
            // inbox mail from the company domain. `in:inbox` also excludes their
            // own Sent mail (they share the domain); a strict address guard below
            // backstops Gmail's fuzzy `from:` matching.
            $internalDomain        = self::internalDomainFor((int) $user->id);
            $mentionNames          = self::mentionNamesFor((int) $user->id);
            $mentionPlusCategories = self::mentionPlusCategoriesFor((int) $user->id);

            $query = (string) config('gmail_insights.query', 'newer_than:2d -category:promotions -category:social -in:chats');
            if ($internalDomain !== null) {
                $query = "from:{$internalDomain} in:inbox {$query}";
            }
            $max   = (int) config('gmail_insights.max_per_scan', 20);

            $list = $gmail->listMessages($max, $query);
            $ids  = array_values(array_filter(array_map(
                fn ($m) => $m['id'] ?? null,
                $list['messages'] ?? []
            )));
            $out['fetched'] = count($ids);
            if (! $ids) {
                return $out;
            }

            // Dedup: skip messages we already stored as important for this user.
            $known = GmailInsight::where('user_id', $user->id)
                ->whereIn('gmail_message_id', $ids)
                ->pluck('gmail_message_id')
                ->all();
            $ids = array_values(array_diff($ids, $known));
            if (! $ids) {
                return $out;
            }

            $messages = $gmail->getMessageSnippets($ids);
            // Drop excluded subjects (e.g. reimbursement) before classification.
            $messages = $this->filterExcludedSubjects($messages);
            // Internal-only recipients: hard-drop anything whose sender address is
            // not exactly @{domain} (Gmail's `from:` is loose; this also rejects
            // lookalike domains like innovfix.in.evil.com).
            if ($internalDomain !== null) {
                $suffix = '@' . $internalDomain;
                $messages = array_values(array_filter($messages, fn ($m) => str_ends_with(
                    $this->extractSenderEmail((string) ($m['from'] ?? '')),
                    $suffix
                )));
            }
            // NOTE: mention-focus recipients (e.g. Ranjini) are NOT pre-filtered
            // here. Their keep/drop decision needs the AI category (Meeting/etc.),
            // which only exists after classify(), so it happens in the store loop.
            $out['scanned'] = count($messages);
            if (! $messages) {
                return $out;
            }

            $verdicts = $this->classify($messages);
            $today    = Carbon::today()->toDateString();

            // Internal "force" senders for this recipient bypass the AI
            // importance gate — every email from them is stored & shown.
            // Internal-only recipients force EVERY (already domain-filtered)
            // message, so all their internal mail is surfaced regardless of verdict.
            $force = self::forceSenderPatternsFor((int) $user->id);

            foreach ($messages as $idx => $msg) {
                $v = $verdicts[$idx] ?? null;

                if ($mentionNames) {
                    // Mention-focus recipients (e.g. Ranjini): FOCUSED tab — store
                    // ONLY emails that mention the user's own name OR fall in an
                    // opted-in category (mention_plus_categories, e.g.
                    // Meeting/Calendar). The generic AI-importance gate does NOT
                    // apply; no other mail is surfaced.
                    $keep = $this->mentionsName($msg, $mentionNames)
                        || in_array((string) ($v['category'] ?? ''), $mentionPlusCategories, true);
                    if (! $keep) {
                        continue;
                    }
                } else {
                    // Normal recipients: store if AI-important OR a force-sender
                    // match. Internal-only recipients (Fida) force every surviving
                    // (already domain-guarded) message past the importance gate.
                    $senderLc = strtolower((string) ($msg['from'] ?? ''));
                    $forced   = $internalDomain !== null;
                    if (! $forced) {
                        foreach ($force as $p) {
                            if ($p !== '' && str_contains($senderLc, $p)) {
                                $forced = true;
                                break;
                            }
                        }
                    }

                    if ((! $v || empty($v['important'])) && ! $forced) {
                        continue;
                    }
                }

                $row = [
                    'subject'          => mb_substr((string) ($msg['subject'] ?? '(no subject)'), 0, 255),
                    'sender'           => mb_substr((string) ($msg['from'] ?? ''), 0, 255),
                    'summary'          => (($v['summary'] ?? '') !== '') ? $v['summary'] : null,
                    'snippet'          => $msg['snippet'] ?? null,
                    'category'         => mb_substr((string) ($v['category'] ?? 'Other'), 0, 50),
                    'priority'         => $v['priority'] ?? 'medium',
                    'received_at'      => $this->parseDate($msg['date'] ?? null),
                    'confidence_score' => $v['confidence'] ?? null,
                    'gmail_thread_id'  => $msg['threadId'] ?? null,
                    'status'           => 'new',
                    'scanned_date'     => $today,
                ];

                $out['important'][] = array_merge(['gmail_message_id' => $msg['id']], $row);

                if ($dryRun) {
                    continue;
                }

                $insight = GmailInsight::firstOrCreate(
                    ['user_id' => $user->id, 'gmail_message_id' => $msg['id']],
                    $row
                );
                if ($insight->wasRecentlyCreated) {
                    $out['created']++;
                }
            }
        } catch (\Throwable $e) {
            $out['error'] = $e->getMessage();
            Log::warning('GmailInsightsService: sync failed', ['user' => $user->id, 'error' => $e->getMessage()]);
        }

        return $out;
    }

    /** Drop messages whose subject matches a configured exclude keyword. */
    private function filterExcludedSubjects(array $messages): array
    {
        $keywords = array_filter(array_map(
            fn ($k) => strtolower(trim((string) $k)),
            (array) config('gmail_insights.exclude_subject_keywords', [])
        ));
        if (! $keywords) {
            return array_values($messages);
        }

        return array_values(array_filter($messages, function ($m) use ($keywords) {
            $subject = strtolower((string) ($m['subject'] ?? ''));
            foreach ($keywords as $kw) {
                if ($kw !== '' && str_contains($subject, $kw)) {
                    return false;
                }
            }
            return true;
        }));
    }

    /**
     * Batch-classify messages in a single Haiku call. Returns a map of
     * message-index => ['important'=>bool, 'category'=>string, 'priority'=>string,
     * 'summary'=>string, 'confidence'=>float].
     */
    private function classify(array $messages): array
    {
        $system = <<<'PROMPT'
You are an email triage assistant for a company work portal. You receive a numbered list of emails (sender, subject, preview). For EACH email decide if it is an important, actionable work notification that belongs on the user's dashboard.

SHOW (important=true): meeting invitations, calendar events, event updates, meeting reschedules/cancellations, client emails, follow-up requests, approval requests, requests for documents/letters/certificates (offer letter, experience letter, salary slip, relieving letter, ID/address proof, etc.), project updates, security alerts, billing/payment alerts, domain or SSL expiry alerts, and important operational notifications.

DO NOT SHOW (important=false): advertisements, promotions, marketing/newsletters, sales campaigns, shopping/order blasts, social media notifications, spam, and generic bulk mail.

For EACH email return one object:
{"i": <the email's number>, "important": <true|false>, "category": "<one of: Meeting, Calendar, Document, Client, Approval, Project, Security, Billing, Alert, Operational, Other>", "priority": "<low|medium|high|urgent>", "summary": "<one short sentence, max 120 chars, describing the action or why it matters>", "confidence": <0.0-1.0>}

Category guide:
- Meeting / Calendar: invitations, events, reschedules — things to attend.
- Document: someone REQUESTING a document/letter/certificate (offer, experience, relieving letter, salary slip, ID/address proof).
- Security: cybersecurity — suspicious login, breach, 2FA, account-security alerts.
- Alert: infrastructure/server/system — server down, uptime, SSL/domain expiry, CI/deploy failures.
- Billing: payments, invoices, subscriptions, receipts (including cloud providers).
- Operational: internal company operations / admin / process notices.

Rules:
- Return ONLY a JSON array. No prose, no markdown code fences.
- Include EVERY input email exactly once, by its number.
- When unsure, prefer important=false — never surface ad/newsletter/promotional content.
- priority: urgent = security/expiry/time-critical today; high = client/approval/meeting soon; medium = normal; low = FYI.
PROMPT;

        $lines = [];
        foreach ($messages as $idx => $m) {
            $n = $idx + 1;
            $from = trim((string) ($m['from'] ?? ''));
            $subj = trim((string) ($m['subject'] ?? ''));
            $prev = trim((string) ($m['snippet'] ?? ''));
            $lines[] = "{$n}. From: {$from} | Subject: {$subj} | Preview: " . mb_substr($prev, 0, 220);
        }
        $userMessage = "Emails:\n" . implode("\n", $lines);

        // temperature 0 → deterministic verdicts, so the same email doesn't flip
        // important/not-important between the every-15-min re-runs.
        $raw = app(TessaAIService::class)->quickAi($system, $userMessage, 0.0);
        $items = $this->parseJsonArray($raw);

        $verdicts = [];
        foreach ($items as $item) {
            if (! is_array($item) || ! isset($item['i'])) {
                continue;
            }
            $idx = (int) $item['i'] - 1; // back to 0-based message index
            if ($idx < 0 || $idx >= count($messages)) {
                continue;
            }
            $priority = strtolower((string) ($item['priority'] ?? 'medium'));
            if (! in_array($priority, self::ALLOWED_PRIORITIES, true)) {
                $priority = 'medium';
            }
            $conf = $item['confidence'] ?? null;
            $conf = is_numeric($conf) ? max(0.0, min(1.0, (float) $conf)) : null;

            $verdicts[$idx] = [
                'important'  => filter_var($item['important'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'category'   => trim((string) ($item['category'] ?? 'Other')) ?: 'Other',
                'priority'   => $priority,
                'summary'    => mb_substr(trim((string) ($item['summary'] ?? '')), 0, 240),
                'confidence' => $conf,
            ];
        }

        return $verdicts;
    }

    /** Decode a JSON array from an AI response, tolerating code fences / stray prose. */
    private function parseJsonArray(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        // Strip ```json ... ``` fences if present.
        $raw = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $raw);
        // Fall back to the first [...] block.
        if (! str_starts_with(ltrim($raw), '[')) {
            if (preg_match('/\[.*\]/s', $raw, $m)) {
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

    private function parseDate(?string $date): ?string
    {
        if (! $date) {
            return null;
        }
        try {
            return Carbon::parse($date)->toDateTimeString();
        } catch (\Throwable $e) {
            return null;
        }
    }
}
