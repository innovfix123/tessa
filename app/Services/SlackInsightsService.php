<?php

namespace App\Services;

use App\Models\Meeting;
use App\Models\SlackInsight;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SlackInsightsService
{
    private const OPENROUTER_URL = 'https://openrouter.ai/api/v1/chat/completions';

    private const MIN_CONTENT_CHARS = 200;
    // Shared (whole-meeting) types. Everything else (action_item / reminder) is
    // routed per the doer/confidence rules in persist().
    private const SHARED_TYPES     = ['decision', 'follow_up'];
    private const ALLOWED_TYPES    = ['action_item', 'reminder', 'follow_up', 'decision'];
    private const ALLOWED_PRIORITIES = ['low', 'medium', 'high', 'urgent'];

    /** Minimum AI-reported confidence to TARGET an action_item/reminder at a
     *  single doer. At/above this (with a resolvable doer) the item goes only to
     *  that person (+ the manager who assigned it). Below this — but still a
     *  plausible action — we don't guess a single owner; the item fans out to
     *  ALL attendees so nothing is lost. */
    private const ASSIGNEE_CONFIDENCE_THRESHOLD = 0.65;

    /** Below this confidence an action_item/reminder is treated as noise and
     *  dropped outright (not even fanned out). Keeps the "nothing lost" fan-out
     *  from flooding everyone with junk the AI itself isn't sure about. */
    private const NOISE_FLOOR = 0.40;

    /** Max attempts to extract ONE note before giving up. After this many
     *  NOTE-LEVEL failures (unparseable/malformed — NOT account-level like a 402),
     *  the note is marked done so a permanently-bad note can't retry-and-rebill
     *  every 10-min cycle. */
    private const MAX_NOTE_ATTEMPTS = 3;

    /** Per-sync running count of AI extraction calls (static so it spans the whole
     *  sync process across the app()-made instances). Reset by resetRunBudget(). */
    private static int $aiCallsThisRun = 0;

    /** Circuit breaker: set when an ACCOUNT-level failure (402 no-credits / 429
     *  rate-limit / 5xx) is seen — the rest of the run then stops calling the model
     *  (every further call would just fail/bill). Notes aren't penalised; they
     *  retry cleanly next sync. */
    private static bool $accountFailureTripped = false;

    /** Set by analyzeWithAI on failure so the caller can distinguish an
     *  account-level failure (abort run, don't penalise the note) from a note-level
     *  one (count toward MAX_NOTE_ATTEMPTS). */
    private ?string $lastFailureKind = null;

    private string $apiKey;

    public function __construct()
    {
        $this->apiKey = config('services.openrouter.api_key', '');
    }

    /**
     * Extract insights from a single Slack meeting/huddle note and persist them.
     *
     * @param  array  $note  output of SlackHuddleSyncService::fetchHuddleNotes() entry
     *                       (must include: canvas_content|huddle_info|text, file_id, ts)
     * @param  Meeting  $meeting  matched Tessa meeting
     * @param  int[]  $attendeeUserIds  attendees detected by the huddle sync (Tessa user IDs)
     * @param  array  $meetingMeta  optional label hints: ['kind' => one_on_one|channel|group|scheduled,
     *                               'channel_name' => string]. Defaults are derived from $note.
     * @return array  ['created' => int, 'skipped' => bool, 'reason' => ?string]
     */
    public function extractFromMeetingNote(array $note, Meeting $meeting, array $attendeeUserIds, array $meetingMeta = []): array
    {
        $fileId = (string) ($note['file_id'] ?? md5(($note['canvas_content'] ?? '') . ($note['ts'] ?? '')));

        // Freshness floor: if `SLACK_INSIGHTS_EARLIEST_TS` is configured, drop
        // any huddle whose note timestamp is older than that cutoff. Use case:
        // first-time enablement, or "I only want suggestions from huddles I had
        // AFTER turning this on" — without this, the 24h cron lookback would
        // surface a full day of historical action items as a sudden backlog.
        $earliest = $this->earliestTimestamp();
        $noteTs   = isset($note['ts']) ? (float) $note['ts'] : 0;
        if ($earliest !== null && $noteTs > 0 && $noteTs < $earliest) {
            $this->debug('skip — older than freshness floor', [
                'meeting_key' => $meeting->meeting_key,
                'note_ts'     => $noteTs,
                'earliest_ts' => $earliest,
            ]);
            return ['created' => 0, 'skipped' => true, 'reason' => 'older than freshness floor'];
        }

        // Short-circuit: a huddle note must be extracted exactly once, EVER —
        // regardless of which meeting it matches. file_id is the note's immutable
        // identity; meeting_id is derived from MUTABLE state (attendee
        // slack_user_id mappings + scheduled-meeting availability at sync time)
        // and can differ between runs. Keying this gate on (meeting_id, file_id)
        // let the SAME note re-extract under a second meeting_id, producing
        // duplicate suggestions AND resurrecting items the user had dismissed.
        // Key on file_id alone.
        $priorRows = SlackInsight::where('source_message_ts', $fileId)->get(['id', 'meeting_id']);

        if ($priorRows->isNotEmpty()) {
            // Already extracted. If those rows were filed under an ad-hoc huddle
            // and THIS run matched a real scheduled meeting, upgrade their label
            // in place (preserving each row's status/dismissals) instead of
            // duplicating — this also lets the recognizable meeting name win.
            $matchedReal = ! Str::startsWith($meeting->meeting_key, 'huddle-');
            $allAdHoc    = $priorRows->every(fn ($r) => Str::startsWith((string) $r->meeting_id, 'huddle-'));
            if ($matchedReal && $allAdHoc) {
                SlackInsight::where('source_message_ts', $fileId)->update([
                    'meeting_id'    => $meeting->meeting_key,
                    'meeting_title' => $meeting->title,
                ]);
                $this->debug('re-pointed ad-hoc rows to scheduled meeting', [
                    'file_id' => $fileId, 'meeting_key' => $meeting->meeting_key,
                ]);
            }
            $this->debug('skip — already extracted', ['file_id' => $fileId, 'meeting_key' => $meeting->meeting_key]);
            return ['created' => 0, 'skipped' => true, 'reason' => 'already extracted'];
        }

        // A note that previously extracted to ZERO items leaves no SlackInsight
        // row, so the file_id gate above can't see it. A lightweight cache marker
        // (database-backed, shared across cron runs) records "already analyzed,
        // nothing actionable" so the premium model isn't re-billed for it on every
        // sync cycle. Set ONLY after a successful empty result — never on failure.
        if ($fileId !== '' && Cache::has($this->extractedCacheKey($fileId))) {
            $this->debug('skip — already extracted (no-item marker)', ['file_id' => $fileId, 'meeting_key' => $meeting->meeting_key]);
            return ['created' => 0, 'skipped' => true, 'reason' => 'already extracted'];
        }

        // Content gate
        $content = trim((string) ($note['canvas_content'] ?? $note['huddle_info'] ?? $note['text'] ?? ''));
        if (strlen($content) < self::MIN_CONTENT_CHARS) {
            $this->debug('skip — content too short', ['meeting_key' => $meeting->meeting_key, 'chars' => strlen($content)]);
            return ['created' => 0, 'skipped' => true, 'reason' => 'content too short'];
        }

        // Normalize the detected (Attendees-section) attendees; empty → skip below.
        $attendees = $this->resolveAttendees($attendeeUserIds, $meeting);
        if (empty($attendees)) {
            $this->debug('skip — no attendees', ['meeting_key' => $meeting->meeting_key]);
            return ['created' => 0, 'skipped' => true, 'reason' => 'no attendees'];
        }

        $meetingDate = isset($note['ts']) && $note['ts']
            ? Carbon::createFromTimestamp((float) $note['ts'], 'Asia/Kolkata')->toDateString()
            : Carbon::today('Asia/Kolkata')->toDateString();

        // Per-sync safeguards, checked right before the (paid) model call:
        //  - circuit breaker: an earlier account-level failure (402/429/5xx) this
        //    run means every further call would just fail/bill → stop calling.
        //  - run cap: bound how many extraction calls one sync makes; overflow
        //    notes defer to the next cycle (dedup → guaranteed forward progress).
        if (self::$accountFailureTripped) {
            return ['created' => 0, 'skipped' => true, 'reason' => 'account failure — run aborted'];
        }
        if (self::$aiCallsThisRun >= $this->maxAiCallsPerRun()) {
            $this->debug('skip — per-run AI call cap reached', ['file_id' => $fileId, 'cap' => $this->maxAiCallsPerRun()]);
            return ['created' => 0, 'skipped' => true, 'reason' => 'run cap reached'];
        }

        $this->debug('extracting', [
            'meeting_key'   => $meeting->meeting_key,
            'meeting_title' => $meeting->title,
            'file_id'       => $fileId,
            'attendees'     => $attendees,
            'content_chars' => strlen($content),
        ]);

        self::$aiCallsThisRun++;
        $this->lastFailureKind = null;
        $aiItems = $this->analyzeWithAI($content, $meeting, $attendees, $meetingDate);

        // null = the AI CALL failed (distinct from a clean empty result).
        if ($aiItems === null) {
            // Account-level failure (402 no-credits / 429 / 5xx) is NOT this note's
            // fault: trip the breaker so the rest of the run stops hammering a dry
            // or rate-limited account, and do NOT count it against the note — it
            // retries cleanly next sync once the account recovers.
            if (in_array($this->lastFailureKind, ['payment', 'rate', 'server'], true)) {
                self::$accountFailureTripped = true;
                Log::warning('SlackInsights: account-level AI failure — aborting run', [
                    'file_id' => $fileId, 'kind' => $this->lastFailureKind,
                ]);
                return ['created' => 0, 'skipped' => true, 'reason' => 'account failure'];
            }
            // Note-level failure (unparseable / other): count attempts and give up
            // after MAX_NOTE_ATTEMPTS so a permanently-bad note can't re-bill forever.
            $attempts = (int) Cache::get($this->attemptsCacheKey($fileId), 0) + 1;
            Cache::put($this->attemptsCacheKey($fileId), $attempts, now()->addDay());
            if ($attempts >= self::MAX_NOTE_ATTEMPTS) {
                $this->markExtracted($fileId);
                Log::warning('SlackInsights: note extraction failed repeatedly — giving up', [
                    'file_id' => $fileId, 'attempts' => $attempts,
                ]);
                return ['created' => 0, 'skipped' => true, 'reason' => 'ai failed (max attempts)'];
            }
            $this->debug('skip — ai request failed (will retry)', ['file_id' => $fileId, 'attempt' => $attempts]);
            return ['created' => 0, 'skipped' => true, 'reason' => 'ai failed (will retry)'];
        }

        if (empty($aiItems)) {
            // The model ran and genuinely found nothing actionable. Mark the note so
            // it isn't re-sent to the premium model on every 10-min sync cycle: a
            // no-item note leaves no SlackInsight row, so the file_id gate can't
            // catch it and it would otherwise re-bill all day until it ages out of
            // the 24h fetch window.
            $this->markExtracted($fileId);
            $this->debug('no items extracted', ['meeting_key' => $meeting->meeting_key]);
            return ['created' => 0, 'skipped' => false, 'reason' => 'no items extracted'];
        }

        $this->debug('AI returned items', [
            'meeting_key' => $meeting->meeting_key,
            'count'       => count($aiItems),
            'items'       => array_map(fn ($i) => [
                'type'        => $i['type'] ?? null,
                'title'       => $i['title'] ?? null,
                'assignee'    => $i['suggested_assignee'] ?? null,
                'assigned_by' => $i['assigned_by'] ?? null,
                'confidence'  => $i['confidence_score'] ?? null,
            ], $aiItems),
        ]);

        // Label hints: prefer values computed by the huddle sync, fall back to
        // what we can infer from the note itself.
        $meetingMeta['channel_name'] = $meetingMeta['channel_name'] ?? (string) ($note['channel_name'] ?? '');
        $meetingMeta['kind']         = $meetingMeta['kind'] ?? null;

        $created = $this->persist($aiItems, $meeting, $attendees, $fileId, $meetingDate, $meetingMeta);

        return ['created' => $created, 'skipped' => false];
    }

    /**
     * Verbose pipeline trace. Gated by `services.slack_insights.debug` (config
     * driven so prod can be quiet without code edits). Off by default.
     */
    private function debug(string $event, array $ctx = []): void
    {
        if (! config('services.slack_insights.debug', false)) return;
        Log::info('SlackInsights[debug]: ' . $event, $ctx);
    }

    /**
     * Cache key marking a note (file_id) as already analyzed. The zero-item case
     * leaves no SlackInsight row for the upstream file_id gate to catch, so
     * without this marker a no-item note re-bills the model on every sync cycle.
     */
    private function extractedCacheKey(string $fileId): string
    {
        return 'slack_insights:extracted:' . $fileId;
    }

    /**
     * Mark a note analyzed (after a successful empty result) so it isn't
     * re-extracted. 7 days comfortably outlasts the 24h huddle fetch window
     * (after which the note is never re-fetched), keeping the cache table bounded.
     */
    private function markExtracted(string $fileId): void
    {
        if ($fileId === '') return;
        Cache::put($this->extractedCacheKey($fileId), 1, now()->addDays(7));
    }

    /** Cache key counting consecutive failed extraction attempts for a note, so a
     *  note that keeps failing at the note level (e.g. unparseable output) is
     *  abandoned after MAX_NOTE_ATTEMPTS instead of retrying — and re-billing —
     *  every cycle. */
    private function attemptsCacheKey(string $fileId): string
    {
        return 'slack_insights:attempts:' . $fileId;
    }

    /** Reset the per-sync call budget + circuit breaker. MUST be called once at
     *  the start of each sync run: PHP-FPM reuses worker processes, so these
     *  statics would otherwise leak across web-triggered syncs (the CLI cron gets
     *  a fresh process each run, but a web "sync now" may not). */
    public static function resetRunBudget(): void
    {
        self::$aiCallsThisRun = 0;
        self::$accountFailureTripped = false;
    }

    /** Hard cap on AI extraction calls per sync run (backstop against runaway
     *  fan-out). Overflow notes defer to the next cycle; dedup guarantees forward
     *  progress so nothing is lost. */
    private function maxAiCallsPerRun(): int
    {
        return max(1, (int) config('services.slack_insights.max_calls_per_run', 60));
    }

    /**
     * Read the freshness-floor unix timestamp from config. Accepts either a
     * unix epoch ('1779800000') or a date string ('2026-05-27', '2026-05-27 14:00')
     * — anything Carbon::parse can read. Returns null if not set.
     */
    private function earliestTimestamp(): ?float
    {
        static $cached = false;
        static $value  = null;
        if ($cached) return $value;
        $cached = true;

        $raw = config('services.slack_insights.earliest_ts');
        if ($raw === null || $raw === '' || $raw === false) return $value = null;

        if (is_numeric($raw)) return $value = (float) $raw;

        try {
            return $value = (float) Carbon::parse((string) $raw, 'Asia/Kolkata')->getTimestamp();
        } catch (\Throwable $e) {
            Log::warning('SlackInsights: invalid SLACK_INSIGHTS_EARLIEST_TS, ignoring', ['raw' => $raw]);
            return $value = null;
        }
    }

    /**
     * Normalize the detected attendee ids (the Attendees-section truth parsed by
     * SlackHuddleSyncService::parseAttendeesSection). NO roster/owner fallback:
     * surfacing nothing beats grafting a scheduled meeting's full roster — or its
     * owner — onto people who never attended the huddle (that fan-out leaked cards
     * to non-attendees). When the result is empty, extractFromMeetingNote skips the
     * note ("no attendees"), which matches the strict-parse "guess nothing" design.
     *
     * The $meeting parameter is retained for call-site stability but unused.
     *
     * @return int[]
     */
    private function resolveAttendees(array $detectedIds, Meeting $meeting): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $detectedIds))));
    }

    /**
     * Send the meeting-note content to the AI and parse extracted items.
     *
     * @return array list of validated insight items
     */
    private function analyzeWithAI(string $content, Meeting $meeting, array $attendeeIds, string $meetingDate): ?array
    {
        if (empty($this->apiKey)) {
            return [];
        }

        $attendeeUsers = User::whereIn('id', $attendeeIds)->get(['id', 'name', 'slack_user_id']);
        $attendeeNames = $attendeeUsers->pluck('name')->all();
        $today = Carbon::now('Asia/Kolkata')->format('Y-m-d');

        // Pre-resolve `<@UXXXX>` / `@UXXXX` Slack mentions in the note text to real
        // names so the AI can identify doers consistently. Without this step, the
        // AI sees opaque IDs and either guesses wrong or — worse — refuses to
        // assign at all (we saw "cannot reliably map Slack IDs to names" warnings
        // in production logs). The resolution covers ALL active users, not just
        // attendees, because Slack notes often mention people who weren't in the
        // huddle (managers giving instructions, recipients of deliverables, etc.).
        [$rewritten, $resolvedMap] = $this->resolveSlackMentions($content);

        $header = "Meeting: " . ($meeting->title ?? 'Untitled') . "\n"
            . "Date: {$meetingDate}\n"
            . "Attendees: " . (empty($attendeeNames) ? 'unspecified' : implode(', ', $attendeeNames)) . "\n";

        if (! empty($resolvedMap)) {
            // Surface the resolved name map so the AI knows exactly which @-mention
            // referred to which person (handy when names also appear bare in text).
            $header .= "Resolved Slack mentions in notes:\n";
            foreach ($resolvedMap as $slackId => $name) {
                $header .= "  @{$slackId} = {$name}\n";
            }
        }

        $header .= "\n----- NOTES -----\n";

        $body = $rewritten;
        // Trim to ~12000 chars for the notes body (header is small)
        if (strlen($body) > 12000) {
            $body = substr($body, 0, 12000) . "\n... (truncated)";
        }

        $digest = $header . $body;

        $systemPrompt = <<<PROMPT
You are Tessa, an AI assistant reading Slack huddle/meeting AI notes. Your job is to extract action items and dashboard insights — but only for people who are ACTUALLY RESPONSIBLE for doing the work.

Today's date: {$today}

# CRITICAL RULE — ownership ≠ mention

A person's name appearing in a sentence does NOT mean they are responsible for the task. Distinguish:
- **assigned_by** = the person giving the instruction or who said the task out loud
- **suggested_assignee** = the person who MUST PERFORM the action (the doer / owner)
- Subjects of the action (mentioned in passing, recipients of an action) are NEITHER

For an action_item / reminder, work hard to identify the single specific DOER from the Attendees list. If the task is for the WHOLE group, set suggested_assignee to "everyone". If you genuinely cannot tell who owns it, set suggested_assignee to null — do NOT guess. Items with no single confident owner are shown to ALL attendees (never dropped), so a wrong guess is worse than leaving it null.

# How to identify the DOER

The assignee comes from the **Action Items** section of the notes — the @mention attached to each task line. A person named only in the Summary, Discussion, or transcript body is NEVER the assignee.

1. Find the imperative verb: "send", "complete", "finish", "update", "connect", "inform", "create", "follow up", "share", "review", "prepare", "schedule", "check"…
2. Ask: WHO must perform that verb?
3. That person is the suggested_assignee — and they MUST be one of the names in the Attendees list.

# Worked examples (Person A / Person B / Person C are placeholder names —
# substitute the actual attendee names you see in the Attendees list)

Notes: "Person A, connect Gmail to Tessa by Friday."
→ doer = Person A (they must connect Gmail). Output 1 action_item with suggested_assignee = "Person A", assigned_by = whoever spoke / "team".

Notes: "Person B assigned Person A to complete the Slack integration."
→ doer = Person A. Output 1 action_item with suggested_assignee = "Person A", assigned_by = "Person B".

Notes: "Person A, please send the documents to Person B."
→ doer = Person A (they must send). Output 1 action_item with suggested_assignee = "Person A", assigned_by = whoever spoke.
→ Person B is the RECIPIENT, not a doer. Do NOT emit an action_item for Person B.

Notes: "Train Person C and assign them a task."
→ doer = the trainer (the manager being instructed). suggested_assignee = the manager from the Attendees list, NOT Person C.
→ If you cannot identify which attendee is the trainer with high confidence, leave suggested_assignee null (and set confidence_score to reflect your uncertainty) — the item will be shown to all attendees rather than dropped.

Notes: "We discussed Person C's onboarding progress."
→ No imperative, no action. Return nothing for this line. (Maybe a decision/follow_up if appropriate.)

Notes: "Person A raised a concern that the API is slow."
→ No clear doer of an action. Could be a follow_up shared with the meeting, but NOT an action_item assigned to Person A.

# Insight types

- **action_item**: A specific task with one clear DOER from the Attendees list.
- **reminder**: A time-sensitive deadline that one specific attendee must remember to handle.
- **follow_up** (shared): Something for the whole meeting to check back on — pending info, blocked work.
- **decision** (shared): A team decision made during the meeting.

# JSON schema — each item

{
  "type": "action_item|reminder|follow_up|decision",
  "title": "Short clear imperative phrasing of what must happen (max 12 words)",
  "summary": "1-2 sentences with context",
  "source_action_item": "The verbatim line(s) from the notes this was extracted from (max 300 chars)",
  "assigned_by": "Name of the person who instructed/assigned the task (from Attendees list), or 'team' if undirected. NOT the doer.",
  "suggested_assignee": "For action_item/reminder: the Attendee name of the single DOER, OR \"everyone\" if the task is for the whole group, OR null if you genuinely can't tell (it will then go to all attendees). MUST be null for follow_up/decision.",
  "priority": "low|medium|high|urgent",
  "due_date": "YYYY-MM-DD if a specific date is mentioned, else null",
  "confidence_score": 0.00-1.00, "Your certainty that this is a real action with a correctly identified doer. Use < 0.65 when ambiguous — those items will be dropped."
}

# Strict rules

- Return [] if nothing genuinely actionable was discussed — DO NOT force items.
- Max 8 items per meeting.
- Skip greetings, status check-ins, descriptions of past events, summaries of discussion.
- Skip items already resolved within the same notes.
- For action_item/reminder, the suggested_assignee MUST be one of the names in the Attendees list, or "everyone", or null. A person named only in the Summary/Discussion/transcript — never under Attendees — is NOT the doer; if a task's only candidate owner isn't an Attendee, set suggested_assignee to null (it then goes to all attendees). Never guess.
- If notes mention `@SlackID` style tokens that DIDN'T appear in the "Resolved Slack mentions" map, treat them as unknown — do NOT invent names for them.
- Never assign an action_item to a person just because they're mentioned. Only because they are the one who has to DO it.
- If a task is addressed to the whole group ("everyone update your trackers"), set suggested_assignee to "everyone" so all attendees see it. If a real task has no identifiable single owner, set suggested_assignee to null — it still goes to all attendees. Reserve a confident single name for when one person clearly owns it.
- assigned_by and suggested_assignee should NOT be the same person unless someone is explicitly assigning a task to themselves ("I'll do X by Friday").
- No markdown, no commentary, ONLY a valid JSON array.
PROMPT;

        $payload = [
            'model'       => config('services.slack_insights.model', 'openai/gpt-5.3-chat'),
            'messages'    => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $digest],
            ],
            'temperature' => 0.3,
        ];

        try {
            $client = new Client(['timeout' => 45, 'connect_timeout' => 10]);
            $response = $client->post(self::OPENROUTER_URL, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type'  => 'application/json',
                    'HTTP-Referer'  => config('app.url', 'https://tessa.innovfix.ai'),
                    'X-Title'       => 'Tessa',
                ],
                'json' => $payload,
            ]);

            $body    = json_decode((string) $response->getBody(), true);
            $raw     = trim($body['choices'][0]['message']['content'] ?? '');
            $clean   = preg_replace('/^```(?:json)?\s*/i', '', $raw);
            $clean   = preg_replace('/\s*```\s*$/', '', $clean);

            $items = json_decode($clean, true);

            // Normalise common output shapes so one stylistic quirk doesn't cost
            // us the whole note (and burn a retry attempt). Kept model-agnostic —
            // no provider-specific response_format — so SLACK_INSIGHTS_MODEL stays
            // freely swappable:
            //   - bare array  [ {...}, {...} ]            → used as-is
            //   - wrapped     { "items": [ {...} ] }      → unwrap the inner array
            //   - single obj  { "type": ..., "title": ... } → wrap into a 1-item array
            if (is_array($items) && ! array_is_list($items)) {
                $unwrapped = null;
                foreach (['items', 'insights', 'action_items', 'results', 'data'] as $key) {
                    if (isset($items[$key]) && is_array($items[$key])) {
                        $unwrapped = $items[$key];
                        break;
                    }
                }
                // No known wrapper key but it looks like a single insight → wrap it.
                $items = $unwrapped !== null
                    ? $unwrapped
                    : (isset($items['title']) || isset($items['type']) ? [$items] : $items);
            }

            if (! is_array($items)) {
                $this->lastFailureKind = 'parse';   // note-level → counts toward attempt cap
                Log::warning('SlackInsights: AI returned non-array', ['content' => substr($clean, 0, 500)]);
                return null;
            }

            return $this->validateItems($items);
        } catch (GuzzleException $e) {
            $this->lastFailureKind = $this->classifyFailure($e);
            Log::error('SlackInsights: AI request failed', ['error' => $e->getMessage(), 'kind' => $this->lastFailureKind]);
            return null;
        } catch (\Throwable $e) {
            $this->lastFailureKind = 'other';
            Log::error('SlackInsights: unexpected error', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /** Classify a Guzzle failure so the caller can tell ACCOUNT-level problems
     *  (402 no-credits, 429 rate-limit, 5xx provider) — which abort the run and do
     *  NOT penalise the note — from request/note-level ones (400 etc.). */
    private function classifyFailure(GuzzleException $e): string
    {
        $code = ($e instanceof \GuzzleHttp\Exception\RequestException && $e->getResponse())
            ? $e->getResponse()->getStatusCode()
            : 0;
        if ($code === 402 || $code === 401 || $code === 403) return 'payment';
        if ($code === 429) return 'rate';
        if ($code >= 500) return 'server';
        return 'other';
    }

    /**
     * Filter AI response down to well-formed items.
     */
    private function validateItems(array $items): array
    {
        $valid = [];
        foreach ($items as $item) {
            if (! is_array($item) || empty($item['title'])) continue;

            $type = $item['type'] ?? 'action_item';
            if (! in_array($type, self::ALLOWED_TYPES, true)) $type = 'action_item';

            $priority = $item['priority'] ?? 'medium';
            if (! in_array($priority, self::ALLOWED_PRIORITIES, true)) $priority = 'medium';

            $dueDate = null;
            if (! empty($item['due_date']) && $item['due_date'] !== 'null') {
                try {
                    $dueDate = Carbon::parse($item['due_date'])->toDateString();
                } catch (\Throwable $e) {
                    $dueDate = null;
                }
            }

            // Confidence: AI may emit float, int, or string. Clamp to [0, 1].
            $confidence = $item['confidence_score'] ?? null;
            if (is_string($confidence)) $confidence = (float) $confidence;
            if (is_numeric($confidence)) {
                $confidence = max(0.0, min(1.0, (float) $confidence));
            } else {
                $confidence = null;
            }

            $assignedBy = isset($item['assigned_by']) ? trim((string) $item['assigned_by']) : null;

            $valid[] = [
                'type'               => $type,
                'title'              => Str::limit((string) $item['title'], 250, ''),
                'summary'            => isset($item['summary']) ? (string) $item['summary'] : null,
                'source_action_item' => isset($item['source_action_item']) ? Str::limit((string) $item['source_action_item'], 1000, '') : null,
                // assigned_by = who instructed; suggested_assignee = who must do it.
                // mentioned_by is kept for back-compat (== assigned_by here).
                'mentioned_by'       => $assignedBy ?: (isset($item['mentioned_by']) ? (string) $item['mentioned_by'] : null),
                'assigned_by'        => $assignedBy,
                'suggested_assignee' => isset($item['suggested_assignee']) ? (string) $item['suggested_assignee'] : null,
                'priority'           => $priority,
                'due_date'           => $dueDate,
                'confidence_score'   => $confidence,
            ];
        }
        return $valid;
    }

    /**
     * Unified persistence — every extracted item becomes ONE audience='meeting'
     * row with an explicit viewer list (audience_user_ids); suggested_assignee_id
     * marks the doer (null = shared / "everyone" / unattributed). Routing:
     *
     *  - decision / follow_up            → all attendees (shared discussion).
     *  - action_item / reminder:
     *      • resolvable doer & confidence ≥ ASSIGNEE_CONFIDENCE_THRESHOLD
     *                                      → [doer] (+ the manager who assigned it,
     *                                        so they can track the delegation).
     *      • "everyone" / no clear doer / confidence ≥ NOISE_FLOOR
     *                                      → all attendees (nothing lost, unassigned).
     *      • confidence < NOISE_FLOOR      → dropped as noise.
     *
     * Dedup via sha1(meeting_key + file_id + slug(title)).
     */
    private function persist(array $aiItems, Meeting $meeting, array $attendeeUserIds, string $fileId, string $meetingDate, array $meetingMeta = []): int
    {
        $created = 0;
        $users   = User::where('is_active', true)->get(['id', 'name', 'reporting_manager_id']);

        $allAttendees = array_values(array_unique(array_map('intval', $attendeeUserIds)));
        $channelName  = trim((string) ($meetingMeta['channel_name'] ?? ''));
        $meetingKind  = $meetingMeta['kind'] ?? null;

        // Large-huddle fan-out narrowing (config-gated). On a channel/group huddle
        // with more than `fanout_max_attendees` people, an UNASSIGNED item would
        // otherwise post a card to every attendee. When this gate trips, such items
        // are funneled to the attendees' managers (+owner) instead — see
        // leadershipAudience(). Never narrows one_on_one/scheduled; `0` disables it.
        $fanoutMax    = (int) config('services.slack_insights.fanout_max_attendees', 6);
        $largeHuddle  = $fanoutMax > 0
            && count($allAttendees) > $fanoutMax
            && in_array($meetingKind, ['channel', 'group'], true);
        $narrowShared = (bool) config('services.slack_insights.narrow_shared_types', false);

        foreach ($aiItems as $item) {
            $titleSlug = Str::slug($item['title']);
            $hashSeed  = $meeting->meeting_key . '|' . $fileId . '|' . $titleSlug;
            $hash      = sha1($hashSeed);

            // Resolve who instructed it (assigner) and who must do it (assignee).
            // The assigner can be any active user — they don't have to be in
            // the matched-attendee set (e.g. a CEO referenced by name).
            $assignerId = $this->matchAnyUser($item['assigned_by'] ?? null, $users);

            $baseRow = [
                'type'                  => $item['type'],
                'title'                 => $item['title'],
                'summary'               => $item['summary'],
                'source_action_item'    => $item['source_action_item'] ?? null,
                'mentioned_by'          => $item['mentioned_by'],
                'assigned_by_user_id'   => $assignerId,
                'source_channel_name'   => $channelName !== ''
                    ? $channelName
                    : '#' . ltrim((string) ($meeting->title ?? 'meeting'), '#'),
                'source_message_ts'    => $fileId,
                'meeting_id'           => $meeting->meeting_key,
                'meeting_title'        => $meeting->title,
                'meeting_date'         => $meetingDate,
                'meeting_kind'         => $meetingKind,
                'meeting_attendee_ids' => $allAttendees,
                'priority'             => $item['priority'],
                'due_date'             => $item['due_date'],
                'confidence_score'     => $item['confidence_score'],
                'status'               => 'new',
                'scanned_date'         => Carbon::today('Asia/Kolkata')->toDateString(),
                'source_note_hash'     => $hash,
            ];

            // ─── Decide the viewer set + the doer (if any) ───────────────────
            if (in_array($item['type'], self::SHARED_TYPES, true)) {
                // decision / follow_up → shared with the whole meeting. On a large
                // channel/group huddle, optionally funnel to managers (+owner) too —
                // OFF by default so team-wide decisions still reach every attendee.
                $viewers     = ($narrowShared && $largeHuddle)
                    ? $this->leadershipAudience($allAttendees, $meeting, $users)
                    : $allAttendees;
                $suggestedId = null;
            } else {
                // action_item / reminder. The doer MUST be one of the Attendees-
                // section attendees. matchAttendee() returns null for
                // "everyone"/"all"/"team" AND for any name not in the section, so a
                // group task — or a name that only appears in the Action Item line
                // without having attended — falls through to the fan-out branch
                // below. No matchAnyUser fallback: a non-attendee can never become
                // the doer/viewer (that was the over-matching bug).
                $doerId = $this->matchAttendee($item['suggested_assignee'] ?? null, $attendeeUserIds, $users);

                // null confidence = AI didn't report one → assume confident enough.
                $confidence = $item['confidence_score'];
                $eff        = $confidence === null ? 1.0 : (float) $confidence;

                if ($doerId && $eff >= self::ASSIGNEE_CONFIDENCE_THRESHOLD) {
                    // Targeted: the doer plus the doer's reporting manager — the one
                    // deliberate exception to "only Attendees-section people see it"
                    // (a manager tracks their report's task even if absent from the
                    // huddle). The assigner is recorded in assigned_by_user_id for
                    // the "delegated by" label only; it is NOT granted visibility.
                    $doer        = $users->firstWhere('id', $doerId);
                    $managerId   = $doer && $doer->reporting_manager_id ? (int) $doer->reporting_manager_id : null;
                    $viewers     = array_values(array_filter([$doerId, $managerId]));
                    $suggestedId = $doerId;
                } elseif ($eff >= self::NOISE_FLOOR) {
                    // "everyone" / no confident single owner → unassigned. Show to
                    // ALL attendees so nothing is lost — UNLESS this is a large
                    // channel/group huddle, where we funnel to the attendees'
                    // managers (+owner) to cut N-way card spam. leadershipAudience()
                    // falls back to all attendees when that set is empty, so a real
                    // item is never routed to nobody.
                    $viewers     = $largeHuddle
                        ? $this->leadershipAudience($allAttendees, $meeting, $users)
                        : $allAttendees;
                    $suggestedId = null;
                    Log::info('SlackInsights: fanned out unassigned item', [
                        'meeting_key'        => $meeting->meeting_key,
                        'title'              => $item['title'],
                        'suggested_assignee' => $item['suggested_assignee'] ?? null,
                        'confidence'         => $confidence,
                        'large_huddle'       => $largeHuddle,
                        'attendee_count'     => count($allAttendees),
                        'audience_size'      => count($viewers),
                    ]);
                } else {
                    // Below the noise floor → drop (genuine junk).
                    Log::info('SlackInsights: dropped — noise', [
                        'meeting_key' => $meeting->meeting_key,
                        'title'       => $item['title'],
                        'confidence'  => $confidence,
                    ]);
                    continue;
                }
            }

            $viewers = array_values(array_unique(array_map('intval', array_filter($viewers))));
            if (empty($viewers)) {
                // Nobody to show it to (e.g. no attendees resolved) → skip.
                continue;
            }

            // Dedup: the note is extracted once (file_id gate upstream), but guard
            // anyway against a same-note re-run producing duplicate rows.
            $exists = SlackInsight::where('source_note_hash', $hash)
                ->whereNull('user_id')
                ->where('audience', 'meeting')
                ->exists();
            if ($exists) continue;

            SlackInsight::create(array_merge($baseRow, [
                'user_id'               => null,
                'audience'              => 'meeting',
                'audience_user_ids'     => $viewers,
                'suggested_assignee_id' => $suggestedId,
            ]));
            $created++;
        }

        Log::info('SlackInsights: meeting note processed', [
            'meeting_key' => $meeting->meeting_key,
            'file_id'     => $fileId,
            'created'     => $created,
        ]);

        return $created;
    }

    /**
     * Rewrite `<@UXXXX>` and bare `@UXXXX` Slack user-mention tokens in $content
     * to `@First Name` so the AI sees readable names. Returns the rewritten body
     * plus a map of slack_id => name for diagnostic logging.
     *
     * Resolves against ALL active users (not just attendees) because Slack notes
     * frequently mention people outside the huddle (managers giving instructions,
     * recipients of deliverables, etc.).
     *
     * @return array{0:string,1:array<string,string>}  [rewrittenContent, resolvedMap]
     */
    private function resolveSlackMentions(string $content): array
    {
        if ($content === '') return [$content, []];

        // One DB hit per call, keyed by slack_user_id for O(1) lookup.
        static $cache = null;
        if ($cache === null) {
            $cache = User::where('is_active', true)
                ->whereNotNull('slack_user_id')
                ->pluck('name', 'slack_user_id')
                ->all();
        }

        if (empty($cache)) return [$content, []];

        $resolved = [];
        $rewritten = preg_replace_callback(
            '/<@(U[A-Z0-9]+)>|(?<![<\w])@(U[A-Z0-9]+)\b/',
            function ($m) use ($cache, &$resolved) {
                $slackId = $m[1] !== '' ? $m[1] : ($m[2] ?? '');
                if ($slackId === '' || ! isset($cache[$slackId])) {
                    return $m[0]; // Leave unknown IDs untouched.
                }
                $resolved[$slackId] = $cache[$slackId];
                return '@' . $cache[$slackId];
            },
            $content
        ) ?? $content;

        return [$rewritten, $resolved];
    }

    /**
     * Match a name to an active user id (not restricted to meeting attendees).
     * Used for the assigner — they might be a CEO/manager named in passing
     * who isn't in the Attendees list themselves.
     */
    private function matchAnyUser(?string $name, $users): ?int
    {
        if (! $name) return null;
        $needle = strtolower(trim($name));
        if (in_array($needle, ['team', 'everyone', 'all', 'unknown', ''], true)) return null;

        $match = $users->first(fn ($u) => strtolower($u->name) === $needle)
            ?? $users->first(fn ($u) => strtolower(explode(' ', $u->name)[0]) === $needle)
            ?? $users->first(fn ($u) => str_contains(strtolower($u->name), $needle));

        return $match ? (int) $match->id : null;
    }

    /**
     * Fuzzy match a name string to a Tessa user id, restricted to meeting attendees.
     */
    private function matchAttendee(?string $name, array $attendeeUserIds, $users): ?int
    {
        if (! $name || empty($attendeeUserIds)) return null;
        $needle = strtolower(trim($name));
        if (in_array($needle, ['team', 'everyone', 'all', 'unknown', ''], true)) return null;

        $candidates = $users->whereIn('id', array_map('intval', $attendeeUserIds));

        $match = $candidates->first(fn ($u) => strtolower($u->name) === $needle)
            ?? $candidates->first(fn ($u) => strtolower(explode(' ', $u->name)[0]) === $needle)
            ?? $candidates->first(fn ($u) => str_contains(strtolower($u->name), $needle));

        return $match ? (int) $match->id : null;
    }

    /**
     * Narrowed audience for an unassigned item on a large huddle: the DISTINCT
     * reporting managers of all attendees (a manager need not have attended — they
     * track their reports' work, mirroring the targeted-branch rule that adds the
     * doer's manager), plus the meeting owner when set. Returns the FULL attendee
     * list unchanged when that leadership set is empty (e.g. an owner-less ad-hoc
     * huddle of peers with no managers), so a real action item is never routed to
     * nobody — the empty-$viewers guard in persist() can't swallow it.
     *
     * @param  int[]  $attendees
     * @return int[]
     */
    private function leadershipAudience(array $attendees, Meeting $meeting, $users): array
    {
        $attendees = array_values(array_unique(array_map('intval', $attendees)));

        $leaders = $users->whereIn('id', $attendees)
            ->pluck('reporting_manager_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->all();

        if (! empty($meeting->owner_id)) {
            $leaders[] = (int) $meeting->owner_id;
        }

        $leaders = array_values(array_unique(array_filter($leaders)));

        return empty($leaders) ? $attendees : $leaders;
    }
}
