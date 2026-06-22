<?php

namespace App\Services;

use App\Models\Meeting;
use App\Models\MeetingAttendance;
use App\Models\MeetingNote;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SlackHuddleSyncService
{
    /**
     * Minutes of Meeting are MANUAL-ONLY: huddle sync must NOT auto-write the
     * AI huddle notes into the MOM (product decision 2026-06-03 — agenda is then
     * AI-extracted only from manually-saved minutes). Attendance tracking and
     * dashboard insight extraction are unaffected; only the note-writing into
     * MeetingNote is gated. Flip to true to restore the old auto-sync-into-MOM.
     */
    private const HUDDLE_WRITES_MOM = false;

    /**
     * Fetch huddle notes from Slack. Default keeps the last-24h window so the
     * 30-min cron stays cheap; pass $sinceTs (unix seconds) to backfill further
     * — including 0 for "all time we can search". Search results are paginated
     * across pages until empty or $maxPages reached.
     */
    public function fetchHuddleNotes(SlackUserService $slack, ?int $sinceTs = null, int $maxPages = 20): array
    {
        // Default: last 24h (matches the cron's old behaviour exactly).
        $sinceTs = $sinceTs ?? (time() - 86400);

        $queries = [
            'huddle notes',
            'A huddle happened',
            'Huddle notes:',
            'took notes for this huddle',
        ];

        $allMessages = [];
        foreach ($queries as $q) {
            for ($page = 1; $page <= $maxPages; $page++) {
                try {
                    $results = $slack->searchMessages($q, 100, $page);
                } catch (\Throwable $e) {
                    break; // Search may fail; move on to next query.
                }
                $matches = $results['messages']['matches'] ?? [];
                if (empty($matches)) break;
                $allMessages = array_merge($allMessages, $matches);

                $totalPages = (int) ($results['messages']['paging']['pages'] ?? 1);
                if ($page >= $totalPages) break;
            }
        }

        $seen = [];
        $unique = [];
        foreach ($allMessages as $msg) {
            $key = ($msg['ts'] ?? '') . '-' . ($msg['channel']['id'] ?? '');
            if (isset($seen[$key])) continue;
            $seen[$key] = true;

            $ts = (float) ($msg['ts'] ?? 0);
            if ($ts < $sinceTs) continue;

            $text    = $msg['text'] ?? '';
            $rawText = $text; // Keep raw for attendee extraction

            // Extract canvas/file content
            $fileId        = null;
            $canvasContent = null;

            if (preg_match('/slack\.com\/docs\/[A-Z0-9]+\/([A-Z0-9]+)/', $text, $fMatch)) {
                $fileId = $fMatch[1];
            }

            if (! $fileId) {
                foreach ($msg['files'] ?? [] as $file) {
                    if (($file['filetype'] ?? '') === 'quip' || ($file['mode'] ?? '') === 'quip') {
                        $fileId = $file['id'] ?? null;
                        break;
                    }
                }
            }

            if ($fileId) {
                $canvasContent = $this->extractCanvasContent($slack, $fileId);
            }

            // Get thread for huddle info
            $huddleInfo    = null;
            $rawHuddleInfo = null;
            $channelId     = $msg['channel']['id'] ?? '';
            if ($channelId && ($msg['ts'] ?? null)) {
                try {
                    $thread = $slack->getThreadReplies($channelId, $msg['ts'], 10);
                    foreach ($thread['messages'] ?? [] as $reply) {
                        $rText = $reply['text'] ?? '';
                        if (str_contains($rText, 'Attendees') || str_contains($rText, 'Summary') || str_contains($rText, 'took notes')) {
                            $rawHuddleInfo = $rText; // Keep raw for attendee extraction
                            $huddleInfo = $rText;
                            break;
                        }
                    }
                } catch (\Throwable $e) {}
            }

            // Clean text for display
            $cleanText = preg_replace('/<([^|>]+)\|([^>]+)>/', '$2', $text);
            $cleanText = preg_replace('/<([^>]+)>/', '$1', $cleanText);
            $cleanText = preg_replace('/:[\w+-]+:/', '', $cleanText);

            $cleanHuddle = $huddleInfo;
            if ($cleanHuddle) {
                $cleanHuddle = preg_replace('/<([^|>]+)\|([^>]+)>/', '$2', $cleanHuddle);
                $cleanHuddle = preg_replace('/<([^>]+)>/', '$1', $cleanHuddle);
                $cleanHuddle = preg_replace('/:[\w+-]+:/', '', $cleanHuddle);
            }

            $unique[] = [
                'text'            => trim($cleanText),
                'raw_text'        => $rawText,
                'channel_id'      => $channelId,
                'channel_name'    => $msg['channel']['name'] ?? '',
                'username'        => $msg['username'] ?? $msg['user'] ?? '',
                'ts'              => $msg['ts'] ?? '',
                'permalink'       => $msg['permalink'] ?? '',
                'date'            => $ts ? date('Y-m-d H:i', (int) $ts) : '',
                'canvas_content'  => $canvasContent,
                'huddle_info'     => $cleanHuddle,
                'raw_huddle_info' => $rawHuddleInfo,
                'file_id'         => $fileId,
            ];
        }

        usort($unique, fn ($a, $b) => (float) $b['ts'] - (float) $a['ts']);

        return ['notes' => $unique, 'count' => count($unique)];
    }

    /**
     * Fetch a Slack Canvas file's plain-text content by file_id. Tries the
     * structured `plain_text`, then the HTML `preview` (tags stripped), then a
     * direct fetch of `url_private`. Returns null when nothing is retrievable
     * (deleted/inaccessible file, revoked token, rate limit, etc.). Shared by the
     * huddle search-sync above and the one-off `slack:rescope-insights` cleanup.
     */
    public function extractCanvasContent(SlackUserService $slack, string $fileId): ?string
    {
        try {
            $fileInfo = $slack->getFileInfo($fileId);
            $file     = $fileInfo['file'] ?? [];
            $canvasContent = $file['plain_text'] ?? null;

            if (! $canvasContent && ($file['preview'] ?? null)) {
                $html = $file['preview'];
                $html = preg_replace('/<br\s*\/?>/i', "\n", $html);
                $html = preg_replace('/<\/p>/i', "\n", $html);
                $html = preg_replace('/<\/h[1-6]>/i', "\n", $html);
                $html = preg_replace('/<\/li>/i', "\n", $html);
                $canvasContent = strip_tags($html);
                $canvasContent = html_entity_decode($canvasContent, ENT_QUOTES, 'UTF-8');
                $canvasContent = preg_replace('/\n{3,}/', "\n\n", trim($canvasContent));
            }

            if (! $canvasContent && ($file['url_private'] ?? null)) {
                try {
                    $resp = Http::withToken($slack->getToken())->get($file['url_private']);
                    if ($resp->successful()) {
                        $body = $resp->body();
                        if (str_contains($body, '<') && str_contains($body, '>')) {
                            $body = preg_replace('/<br\s*\/?>/i', "\n", $body);
                            $body = preg_replace('/<\/p>/i', "\n", $body);
                            $body = strip_tags($body);
                            $body = html_entity_decode($body, ENT_QUOTES, 'UTF-8');
                            $body = preg_replace('/\n{3,}/', "\n\n", trim($body));
                        }
                        $canvasContent = $body;
                    }
                } catch (\Throwable $e) {}
            }

            return $canvasContent;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Sync all huddle notes to matching Tessa meetings.
     *
     * @param  int|null  $sinceTs  Unix timestamp lower bound. Null = last 24h.
     */
    public function syncAll(?User $callerUser = null, bool $attendanceOnly = false, bool $withInsights = false, ?int $sinceTs = null): array
    {
        // Fresh per-run AI budget + circuit breaker for the insight extractor
        // (statics survive across PHP-FPM requests, so reset them explicitly).
        SlackInsightsService::resetRunBudget();

        // Find a Slack-connected user
        $user = $callerUser && $callerUser->hasSlackConnection()
            ? $callerUser
            : User::whereNotNull('slack_access_token')->where('is_active', true)->first();

        if (! $user) {
            return ['synced' => 0, 'skipped' => 0, 'errors' => 0, 'message' => 'No Slack-connected user found'];
        }

        try {
            $slack = SlackUserService::forUser($user);
        } catch (\Throwable $e) {
            return ['synced' => 0, 'skipped' => 0, 'errors' => 1, 'message' => $e->getMessage()];
        }

        Log::info('SlackHuddleSync: starting sync from user view', [
            'caller_user_id'   => $user->id,
            'caller_user_name' => $user->name,
            'since_ts'         => $sinceTs,
            'attendance_only'  => $attendanceOnly,
            'with_insights'    => $withInsights,
        ]);

        $data  = $this->fetchHuddleNotes($slack, $sinceTs);
        $notes = $data['notes'] ?? [];

        Log::info('SlackHuddleSync: huddle notes fetched', [
            'caller_user_id' => $user->id,
            'count'          => count($notes),
            'file_ids'       => array_values(array_filter(array_column($notes, 'file_id'))),
        ]);

        $synced  = 0;
        $skipped = 0;
        $errors  = 0;
        $details = [];

        foreach ($notes as $note) {
            try {
                $result = $this->syncSingleNote($note, $attendanceOnly, $withInsights, $slack);
                if ($result['status'] === 'synced') {
                    $synced++;
                } else {
                    $skipped++;
                }
                $details[] = $result;
            } catch (\Throwable $e) {
                $errors++;
                $details[] = ['status' => 'error', 'error' => $e->getMessage()];
            }
        }

        return compact('synced', 'skipped', 'errors', 'details');
    }

    /**
     * Sync a single huddle note to its matching meeting.
     */
    public function syncSingleNote(array $note, bool $attendanceOnly = false, bool $withInsights = false, ?SlackUserService $slack = null): array
    {
        $ts = (float) ($note['ts'] ?? 0);
        if (! $ts) return ['status' => 'skipped', 'reason' => 'no timestamp'];

        $huddleTime = Carbon::createFromTimestamp($ts, 'Asia/Kolkata');

        // 1. Resolve attendees STRICTLY from the AI-notes "Attendees" section —
        // never the transcript body. `count` includes named-but-unmapped people so
        // meeting_kind reflects the true headcount, while only resolvable teammates
        // (user_ids) ever gain audience.
        $attendeeParse       = $this->parseAttendeesSection($this->attendeesSource($note), null, $slack);
        $tessaUserIds        = $attendeeParse['user_ids'];
        $unknownParticipants = max(0, $attendeeParse['count'] - count($tessaUserIds));

        // 2. Find matching meeting (by time + attendees). When no Tessa meeting
        // lines up — typical for ad-hoc 1-on-1 huddles — fall back to a synthetic
        // in-memory Meeting so insights still extract and surface on dashboards.
        $match = $this->findMatchingMeeting($huddleTime, $tessaUserIds, $unknownParticipants);

        // 2b. No "Attendees" section at ALL (found=false) → seed attendees from
        // structural ground truth that needs no Canvas (a DM huddle's counterpart,
        // or a scheduled roster). Never reads the transcript body, so the
        // over-matching bug can't return; a section that WAS present but unresolved
        // (found=true) is deliberately left alone. With real attendee evidence the
        // meeting match can then resolve too (e.g. a scheduled 1:1).
        if (! $attendeeParse['found'] && empty($tessaUserIds)) {
            $seedMeeting = is_array($match) ? $match['meeting'] : null;
            $structIds   = $this->structuralAttendeesFor($seedMeeting, $note);
            if (! empty($structIds)) {
                $tessaUserIds = $structIds;
                $match = $match ?? $this->findMatchingMeeting($huddleTime, $tessaUserIds, $unknownParticipants);
            }
        }

        $isAdHoc  = ! $match;

        if ($isAdHoc) {
            $meeting   = $this->buildAdHocMeeting($note, $huddleTime, $tessaUserIds);
            $meetingId = $meeting->meeting_key;
        } else {
            $meeting   = $match['meeting'];
            $meetingId = $match['meeting_id'];
        }

        $weekKey = $this->computeWeekKey($huddleTime);

        // 3. Record attendance (only for real meetings — ad-hoc huddles have no
        // Tessa Meeting row to anchor attendance to).
        $attendanceCounts = $isAdHoc
            ? ['present' => count($tessaUserIds), 'absent' => 0]
            : $this->recordAttendance($meetingId, $meeting, $huddleTime->toDateString(), $tessaUserIds);

        // 4. Extract dashboard insights (independent of attendance-only mode).
        // Insights don't require writing to MeetingNote — they live in slack_insights.
        $insightsCreated = 0;
        if ($withInsights) {
            try {
                // Label hints for the suggestion cards: a 2-person huddle is a
                // 1:1 (even inside a channel); a multi-person ad-hoc huddle in a
                // named channel is "<channel> huddle"; anything matched to a
                // scheduled meeting keeps that meeting's name.
                $channelName  = trim((string) ($note['channel_name'] ?? ''));
                // A DM/IM huddle carries the other person's Slack ID as its "channel
                // name", not a real channel. A single Slack ID = a 1:1 DM: resolve the
                // counterpart into attendees (so the label shows their name), drop the
                // bogus channel name, and classify it one_on_one. When the counterpart
                // can't be resolved the one_on_one label path shows "Unknown huddle".
                $isDmId = $channelName !== '' && preg_match('/^[UW][A-Z0-9]{6,}$/', $channelName);
                if ($isDmId) {
                    $dmUserId = User::where('slack_user_id', $channelName)->value('id');
                    if ($dmUserId && ! in_array((int) $dmUserId, $tessaUserIds, true)) {
                        $tessaUserIds[] = (int) $dmUserId;
                    }
                    $channelName = '';
                }
                // Headcount comes from the AI-notes Attendees SECTION (mapped +
                // unmapped). 2 people = a 1:1 (even inside a channel); 3+ = a named
                // channel huddle or an ad-hoc group. A DM is always a 1:1.
                $attendeeCount = count(array_unique(array_map('intval', $tessaUserIds))) + $unknownParticipants;
                $meetingKind   = ! $isAdHoc                        ? 'scheduled'
                               : ($isDmId || $attendeeCount === 2  ? 'one_on_one'
                               : ($channelName !== ''              ? 'channel' : 'group'));

                $extractor = app(SlackInsightsService::class);
                $result    = $extractor->extractFromMeetingNote($note, $meeting, $tessaUserIds, [
                    'kind'         => $meetingKind,
                    'channel_name' => $channelName,
                ]);
                $insightsCreated = (int) ($result['created'] ?? 0);
            } catch (\Throwable $e) {
                Log::warning('SlackHuddleSync: insight extraction failed', [
                    'meeting_key' => $meetingId,
                    'error'       => $e->getMessage(),
                ]);
            }
        }

        Log::info('SlackHuddleSync: huddle pipeline completed', [
            'meeting_key'      => $meetingId,
            'ad_hoc'           => $isAdHoc,
            'file_id'          => $note['file_id'] ?? null,
            'attendees'        => $tessaUserIds,
            'insights_created' => $insightsCreated,
        ]);

        // Ad-hoc huddles never write to MeetingNote — there's no scheduled
        // Tessa meeting to attach to. Insights are the whole point for them.
        if ($isAdHoc) {
            return [
                'status'           => 'synced',
                'meeting'          => $meeting->title,
                'meeting_key'      => $meetingId,
                'ad_hoc'           => true,
                'attendance'       => $attendanceCounts,
                'insights_created' => $insightsCreated,
            ];
        }

        if ($attendanceOnly) {
            return [
                'status'           => 'synced',
                'meeting'          => $meeting->title,
                'meeting_key'      => $meetingId,
                'attendance'       => $attendanceCounts,
                'insights_created' => $insightsCreated,
            ];
        }

        // 5 & 6. Writing the huddle notes into the Minutes of Meeting is DISABLED
        // — MOMs are manual-only (see HUDDLE_WRITES_MOM). Attendance + insights
        // above already ran; we skip the MeetingNote write and report synced.
        if (self::HUDDLE_WRITES_MOM) {
            $content = $note['canvas_content'] ?? $note['huddle_info'] ?? $note['text'] ?? '';
            if (! empty(trim($content))) {
                $fileId = $note['file_id'] ?? md5($content);
                $this->writeNotes($meetingId, $weekKey, $content, $fileId);
            }
        }

        return [
            'status'           => 'synced',
            'meeting'          => $meeting->title,
            'meeting_key'      => $meetingId,
            'week_key'         => $weekKey,
            'attendance'       => $attendanceCounts,
            'insights_created' => $insightsCreated,
        ];
    }

    /**
     * Build a non-persisted Meeting representing an ad-hoc Slack huddle that
     * didn't match any scheduled Tessa meeting. The meeting_key is derived from
     * the file_id so re-runs hit SlackInsightsService's dedup gate.
     */
    private function buildAdHocMeeting(array $note, Carbon $huddleTime, array $tessaUserIds): Meeting
    {
        $fileId = (string) ($note['file_id'] ?? $note['ts'] ?? '');
        $key    = 'huddle-' . substr(sha1($fileId !== '' ? $fileId : ($note['raw_text'] ?? uniqid())), 0, 24);

        // Title preference: parse "Huddle notes: ..." heading if present;
        // fall back to date + attendee initials.
        $heading = null;
        $rawText = ($note['raw_text'] ?? '') . "\n" . ($note['raw_huddle_info'] ?? '');
        if (preg_match('/^\s*(?:\*?Huddle notes[:\s]*[^\n]+)/im', $rawText, $hm)) {
            $heading = trim(preg_replace('/[*<>]/', '', $hm[0]));
        }
        if (! $heading) {
            $names = User::whereIn('id', $tessaUserIds)->pluck('name')->take(3)->implode(', ');
            $heading = 'Slack Huddle · ' . $huddleTime->format('d M Y H:i')
                . ($names !== '' ? ' · ' . $names : '');
        }

        $meeting = new Meeting([
            'title'      => $heading,
            'attendees'  => array_values(array_unique(array_map('intval', $tessaUserIds))),
            'recurrence' => 'none',
        ]);
        $meeting->meeting_key = $key;

        return $meeting;
    }

    /**
     * Pick the text to parse attendees from: the Slack-generated AI notes only —
     * the Canvas (`canvas_content`) first, then the thread-reply copy
     * (`huddle_info` / `raw_huddle_info`). NEVER the transcript body (`text` /
     * `raw_text`): names merely mentioned in conversation must not be read as
     * attendance. That conflation was the root over-matching bug.
     */
    private function attendeesSource(array $note): string
    {
        foreach (['canvas_content', 'huddle_info', 'raw_huddle_info'] as $k) {
            $v = trim((string) ($note[$k] ?? ''));
            if ($v !== '') return $v;
        }

        return '';
    }

    /**
     * Parse the explicit "Attendees" (a.k.a. Participants / Present) section of
     * Slack AI huddle notes into Tessa user IDs. ONLY the people named in that
     * section count — never names that merely appear elsewhere in the notes.
     *
     * `count` is the number of people NAMED in the section (resolvable or not), so
     * a huddle with an unmapped participant is still classified by its true
     * headcount while only resolvable teammates (`user_ids`) ever gain audience.
     * `found` is false when no Attendees section exists at all (→ surface nothing
     * rather than guess from the body).
     *
     * Public so the one-off `slack:rescope-insights` cleanup can re-parse the same
     * way on re-fetched notes.
     *
     * @param  string  $aiNotes  AI-notes text (see attendeesSource()); never the transcript body.
     * @param  \Illuminate\Support\Collection|null  $users  active users (id,name,slack_user_id); loaded if null.
     * @return array{user_ids:int[], count:int, found:bool}
     */
    public function parseAttendeesSection(string $aiNotes, $users = null, ?SlackUserService $slack = null): array
    {
        $aiNotes = (string) $aiNotes;
        if (trim($aiNotes) === '') {
            return ['user_ids' => [], 'count' => 0, 'found' => false];
        }

        if ($users === null) {
            $users = User::where('is_active', true)->get(['id', 'name', 'slack_user_id', 'email']);
        }

        // Isolate the section body. The heading is anchored to a line start (so the
        // bare word "attendees" inside a sentence can't trigger it) and may be led
        // by leading decoration: whitespace, bullets, markdown, a unicode emoji, OR
        // a Slack `:emoji_name:` shortcode. The url_private Canvas export keeps
        // emojis as shortcodes AND strips the newline between a heading and its
        // content, so the section reads ":handshake: Attendees@U1 and @U2" with the
        // next heading (":star: Summary…") on the following line. `$dec` swallows
        // both emoji forms without eating heading letters. The body runs to the
        // first of: a blank line, the next known section heading, or end-of-text.
        // `$dec` (leading decoration) tries a whole `:emoji:` shortcode FIRST, then
        // any single non-letter/digit char — otherwise the bare ":" gets eaten and
        // ":handshake:" never matches as a unit. `$emoji` is a unicode-emoji class.
        // The Slack export glues each heading to its content ("SummaryCompany…"), so
        // a trailing `\b` on a heading word is unreliable; instead the body
        // terminates at the NEXT emoji-led section line (every section starts with
        // an emoji: ":star: Summary", ":white_check_mark: Action items"), a blank
        // line, a plain known heading, or end-of-text.
        $dec      = '(?::[a-z0-9_+\-]+:|[^\p{L}\p{N}\n])*';
        $emoji    = '[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{2190}-\x{21FF}\x{2300}-\x{23FF}]';
        $headings = 'summary|action[ \t]*items?|action[ \t]*points?|to[ \t]*-?dos?|decisions?|notes?|'
            . 'discussion|topics?|key[ \t]*points?|highlights?|follow[ \t]*-?ups?|next[ \t]*steps?|'
            . 'agenda|recording|transcript';
        // `(?:[ \t]*\n)?` after the colon swallows ONE heading→content line break
        // so the blank-line layout ("Attendees\n\n@a, @b") captures the names on
        // the following line instead of terminating on the gap and returning an
        // empty body. It stays optional, so the glued ("Attendees@a") and
        // same-line (": @a") layouts are unaffected; an empty section still ends
        // at the next emoji-led heading (the terminator below fires first).
        $re = '/(?:^|\n)' . $dec . '(?:attendees?|participants?|present)\b[ \t]*:?(?:[ \t]*\n)?[ \t]*'
            . '(.*?)'
            . '(?=\n[ \t]*\n|\n[ \t]*(?::[a-z0-9_+\-]+:|' . $emoji . ')|\n' . $dec . '(?:' . $headings . ')\b|\z)/isu';

        if (! preg_match($re, $aiNotes, $m)) {
            return ['user_ids' => [], 'count' => 0, 'found' => false];
        }

        $body = trim($m[1]);
        if ($body === '') {
            return ['user_ids' => [], 'count' => 0, 'found' => true];
        }

        // One attendee per token. Split on list separators ONLY (not spaces), so a
        // two-word name like "Dhana Lakshmi" stays intact. `\band\b` won't split
        // names that merely contain the letters "and" (e.g. "Anand", "Chandni").
        $tokens = preg_split('/\s*(?:,|;|\n|•|·|\*|&|\band\b)\s*/iu', $body, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $ids        = [];
        $count      = 0;
        $unresolved = [];   // tokens named in the section we could not map to a Tessa user
        foreach ($tokens as $tok) {
            // Strip leading list/blockquote/emphasis markers and trailing emphasis,
            // but keep a trailing ">" so a "<@U…>" mention token stays intact.
            $tok = trim((string) $tok);
            $tok = ltrim($tok, ">*_~`- \t");
            $tok = rtrim($tok, "*_~`- \t");
            if ($tok === '') continue;

            // Raw Slack ID: <@U…>, <@U…|Display>, or a bare @U… / U… token.
            if (preg_match('/<@(U[A-Z0-9]+)(?:\|[^>]*)?>|^@?(U[A-Z0-9]+)$/', $tok, $sm)) {
                $sid = $sm[1] !== '' ? $sm[1] : ($sm[2] ?? '');
                $count++;
                $u = $users->firstWhere('slack_user_id', $sid);
                if ($u) {
                    $ids[(int) $u->id] = true;
                } elseif (($eid = $this->resolveSlackIdByEmail($sid, $users, $slack)) !== null) {
                    // slack_user_id isn't stored for ~9/53 users, so the lookup above
                    // misses them. Resolve the SAME person via their Slack profile
                    // email → users.email. Still strictly an Attendees-section name;
                    // never adds anyone outside the section.
                    $ids[$eid] = true;
                } else {
                    $unresolved[] = $sid;
                }
                continue;
            }

            // Display @Name or plain Name → fuzzy match (exact → first name → contains).
            $name = preg_replace('/<[^>]*>/', '', $tok);   // drop any residual <…|…> link
            $name = trim(ltrim((string) $name, '@'));
            if (mb_strlen($name) < 2) continue;
            $count++;
            $nl = mb_strtolower($name);
            $u = $users->first(fn ($x) => mb_strtolower((string) $x->name) === $nl)
                ?? $users->first(fn ($x) => mb_strtolower(explode(' ', (string) $x->name)[0]) === $nl)
                ?? $users->first(fn ($x) => str_contains(mb_strtolower((string) $x->name), $nl));
            if ($u) {
                $ids[(int) $u->id] = true;
            } else {
                $unresolved[] = $name;
            }
        }

        if (! empty($unresolved)) {
            // Observability for the silent-drop class: a person named in the
            // Attendees section we couldn't map to a Tessa user never sees their
            // own action item. Logged (not debug-gated) so real misses are visible
            // and fixable. Does NOT affect user_ids/count/found.
            Log::info('SlackHuddleSync: attendees section had unresolved tokens', [
                'unresolved'     => array_values(array_unique($unresolved)),
                'resolved_count' => count($ids),
                'named_count'    => $count,
            ]);
        }

        return ['user_ids' => array_map('intval', array_keys($ids)), 'count' => $count, 'found' => true];
    }

    /**
     * Fallback resolver for an Attendees-section `<@U…>` token whose Slack id is
     * NOT stored on any users.slack_user_id (≈9/53 active users have none). Asks
     * Slack for that user's profile email and matches it to users.email — the same
     * human, resolved by a different key.
     *
     * Only runs when a Slack client is available (the live cron path); the rescope
     * command passes none, so its behaviour is unchanged. Results (including
     * misses) are memoised per process to bound API calls across a multi-note run.
     */
    private function resolveSlackIdByEmail(string $slackId, $users, ?SlackUserService $slack): ?int
    {
        if ($slack === null || $slackId === '') return null;

        static $memo = [];
        if (array_key_exists($slackId, $memo)) return $memo[$slackId];

        try {
            $info  = $slack->getUserInfo($slackId);
            $email = mb_strtolower(trim((string) ($info['user']['profile']['email'] ?? '')));
        } catch (\Throwable $e) {
            return $memo[$slackId] = null;
        }

        if ($email === '') return $memo[$slackId] = null;

        $u = $users->first(fn ($x) => mb_strtolower((string) ($x->email ?? '')) === $email);

        return $memo[$slackId] = $u ? (int) $u->id : null;
    }

    /**
     * Find a Tessa meeting matching by time window and attendee overlap.
     * Time window: 10 min before to 90 min after meeting start time.
     */
    private function findMatchingMeeting(Carbon $huddleTime, array $tessaUserIds, int $unknownParticipants = 0): ?array
    {
        // No attendee evidence at all → never latch onto a meeting on time alone.
        // (An empty list previously time-matched the nearest scheduled meeting,
        // yielding zero insights but recording its entire roster ABSENT.)
        if (empty($tessaUserIds) && $unknownParticipants === 0) {
            return null;
        }

        $dayName  = $huddleTime->format('l');
        $isWeekday = ! $huddleTime->isWeekend();

        if (! $isWeekday) return null;

        // Get skip dates
        $skipKeys = DB::table('meeting_skips')
            ->where('skip_date', $huddleTime->toDateString())
            ->pluck('meeting_key')
            ->toArray();

        // Find meetings that could occur on this day. One-time meetings with a
        // pinned meeting_date only match when that date equals the huddle date —
        // otherwise an HR eval on Tue 30 Jun would match every Tuesday huddle.
        $huddleDateStr = $huddleTime->toDateString();
        $meetings = Meeting::where(function ($q) use ($dayName, $huddleDateStr) {
            $q->where('recurrence', 'daily_weekdays')
                ->orWhere(function ($q2) use ($dayName) {
                    $q2->where('recurrence', 'weekly')->where('day_of_week', $dayName);
                })
                ->orWhere(function ($q2) use ($dayName, $huddleDateStr) {
                    $q2->where('recurrence', 'none')
                        ->where('day_of_week', $dayName)
                        ->where(function ($d) use ($huddleDateStr) {
                            $d->whereNull('meeting_date')
                                ->orWhereDate('meeting_date', $huddleDateStr);
                        });
                });
            $multiDayCheck = [
                'tue_to_fri' => ['Tuesday', 'Wednesday', 'Thursday', 'Friday'],
                'mon_thu'    => ['Monday', 'Thursday'],
                'mon_wed_fri'=> ['Monday', 'Wednesday', 'Friday'],
            ];
            foreach ($multiDayCheck as $recurrence => $days) {
                if (in_array($dayName, $days, true)) {
                    $q->orWhere('recurrence', $recurrence);
                }
            }
        })->get();

        $bestMatch = null;
        $bestScore = -1;

        foreach ($meetings as $meeting) {
            if (in_array($meeting->meeting_key, $skipKeys)) continue;

            // Parse meeting time
            $meetingMinutes = $this->timeToMinutes($meeting->time);
            if ($meetingMinutes === null) continue;

            $meetingTime = $huddleTime->copy()->startOfDay()->addMinutes($meetingMinutes);
            $diffMins    = $huddleTime->diffInMinutes($meetingTime, false); // negative = huddle is after meeting

            // Check attendee overlap FIRST — the roster evidence decides how
            // generous the time window below may be.
            $meetingAttendees = $meeting->attendees ?? [];
            if ($meeting->owner_id && ! in_array($meeting->owner_id, $meetingAttendees)) {
                $meetingAttendees[] = $meeting->owner_id;
            }

            $overlap = count(array_intersect($tessaUserIds, $meetingAttendees));

            if ($overlap === 0 && ! empty($tessaUserIds)) continue;

            // A huddle bringing in as many (or more) non-roster people as roster
            // members is a *different* gathering (e.g. an ad-hoc 1:1) that merely
            // overlaps this meeting's time + one member — not this scheduled
            // meeting. Matching it would mis-attribute its insights AND record
            // false attendance. Fall through to ad-hoc instead.
            //
            // Unmapped Slack participants (tagged <@U…> in the huddle but not
            // linked to any Tessa user) are off-roster people too. Without
            // counting them, a 1:1 with an unmapped colleague looks like "one
            // roster member alone near the meeting time" and gets wrongly absorbed.
            $strangers = count(array_diff($tessaUserIds, $meetingAttendees)) + $unknownParticipants;
            if ($overlap > 0 && $strangers >= $overlap) continue;

            // Time window: normally 10 min early to 90 min after the scheduled
            // start (90 min covers meeting duration + Slack notes delay). diffMins
            // > 0 means the huddle is BEFORE the scheduled time, < 0 means after.
            //
            // The tight 10-min-early bound assumes a meeting never starts much
            // ahead of its slot — but when a standup's whole roster gathers early
            // (a "12:00" intern standup actually held ~11:00) that huddle would be
            // dropped to ad-hoc and its attendance lost. When the roster evidence
            // is STRONG — ≥2 roster members together AND nobody off-roster
            // (strangers === 0) — widen the early bound to 90 min so the early
            // group huddle still matches. Weak single-member overlaps keep the
            // tight 10-min bound, so an unrelated earlier huddle that merely shares
            // one member is still NOT absorbed.
            $earlyBound = ($overlap >= 2 && $strangers === 0) ? 90 : 10;
            if ($diffMins > $earlyBound || $diffMins < -90) continue;

            // Score: overlap count * 100 - time difference (higher is better)
            $score = ($overlap * 100) - abs($diffMins);

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestMatch = $meeting;
            }
        }

        if (! $bestMatch) return null;

        $meetingId = $this->resolveDayMeetingId($bestMatch, $dayName);

        return ['meeting' => $bestMatch, 'meeting_id' => $meetingId];
    }

    /**
     * Structural attendee ground truth that needs NO Canvas — used ONLY as a
     * fallback when the AI notes carry no "Attendees" section at all (parse
     * found=false). Mirrors the trusted hierarchy of slack:rescope-insights:
     *   - a real SCHEDULED meeting → its roster (attendees + owner), else
     *   - a DM huddle (channel_name is a bare Slack id) → the counterpart.
     * Reads only structural signals (the meetings table / the DM id), never the
     * transcript body, so it cannot resurface the over-matching bug. Returns []
     * when no structural signal applies (a genuine ad-hoc group stays unrescued).
     *
     * @return int[]
     */
    public function structuralAttendeesFor(?Meeting $meeting, array $note): array
    {
        // Scheduled meeting → authoritative roster from the meetings table.
        if ($meeting && ! str_starts_with((string) $meeting->meeting_key, 'huddle-')) {
            $att = is_array($meeting->attendees) ? $meeting->attendees : [];
            if ($meeting->owner_id) {
                $att[] = $meeting->owner_id;
            }
            $att = array_values(array_unique(array_map('intval', array_filter($att))));
            if (! empty($att)) return $att;
        }

        // DM huddle: channel_name is a bare Slack id ⇒ a 1:1 → resolve the counterpart.
        $ch = ltrim(trim((string) ($note['channel_name'] ?? '')), '#');
        if ($ch !== '' && preg_match('/^[UW][A-Z0-9]{6,}$/', $ch)) {
            $dmUserId = User::where('slack_user_id', $ch)->value('id');
            if ($dmUserId) {
                return [(int) $dmUserId];
            }
        }

        return [];
    }

    /**
     * Write notes to MeetingNote (append if manual, deduplicate by marker).
     */
    private function writeNotes(string $meetingId, string $weekKey, string $content, string $fileId): bool
    {
        $marker = "<!-- slack-huddle-sync:{$fileId} -->";

        $existing = MeetingNote::where('meeting_id', $meetingId)
            ->where('week_key', $weekKey)
            ->first();

        // Already synced?
        if ($existing && str_contains($existing->content ?? '', $marker)) {
            return false;
        }

        $huddleBlock = "\n\n---\n**Slack Huddle Notes (auto-synced)**\n\n{$content}\n{$marker}";

        if ($existing && ! empty(trim($existing->content ?? ''))) {
            // Has manual notes — append
            $existing->update([
                'content' => $existing->content . $huddleBlock,
            ]);
        } else {
            // No existing notes — create
            MeetingNote::updateOrCreate(
                ['meeting_id' => $meetingId, 'week_key' => $weekKey],
                ['content' => "**Slack Huddle Notes (auto-synced)**\n\n{$content}\n{$marker}"]
            );
        }

        Log::info('SlackHuddleSync: notes written', ['meeting_id' => $meetingId, 'week_key' => $weekKey]);
        return true;
    }

    private function recordAttendance(string $meetingId, Meeting $meeting, string $occurrenceDate, array $detectedUserIds): array
    {
        $expectedUserIds = $meeting->attendees ?? [];
        if ($meeting->owner_id && ! in_array($meeting->owner_id, $expectedUserIds)) {
            $expectedUserIds[] = $meeting->owner_id;
        }

        $marked = ['present' => 0, 'absent' => 0];

        foreach ($detectedUserIds as $userId) {
            MeetingAttendance::updateOrCreate(
                ['meeting_id' => $meetingId, 'occurrence_date' => $occurrenceDate, 'user_id' => $userId],
                ['status' => 'present', 'source' => 'slack_huddle_sync']
            );
            $marked['present']++;
        }

        $absentUserIds = array_diff($expectedUserIds, $detectedUserIds);
        foreach ($absentUserIds as $userId) {
            $existing = MeetingAttendance::where('meeting_id', $meetingId)
                ->where('occurrence_date', $occurrenceDate)
                ->where('user_id', $userId)
                ->first();

            // Presence is STICKY across multiple huddles on the same meeting+date.
            // A standup day often has several huddle files (the group call + 1:1
            // check-ins); each is parsed independently. Without this guard, a later
            // 1:1 huddle that only detects one person would overwrite everyone else
            // back to 'absent', clobbering the group huddle's attendance. Once a
            // person is marked present (detected in ANY huddle that day) they stay
            // present — only people detected in NO huddle end up absent.
            if ($existing && $existing->status === 'present') {
                continue;
            }

            if (! $existing || $existing->source === 'slack_huddle_sync') {
                MeetingAttendance::updateOrCreate(
                    ['meeting_id' => $meetingId, 'occurrence_date' => $occurrenceDate, 'user_id' => $userId],
                    ['status' => 'absent', 'source' => 'slack_huddle_sync']
                );
                $marked['absent']++;
            }
        }

        Log::info('SlackHuddleSync: attendance recorded', [
            'meeting_id' => $meetingId,
            'date' => $occurrenceDate,
            'present' => $marked['present'],
            'absent' => $marked['absent'],
        ]);

        return $marked;
    }

    private function computeWeekKey(Carbon $date): string
    {
        return $date->copy()->startOfWeek(Carbon::MONDAY)->format('Y-m-d');
    }

    private function resolveDayMeetingId(Meeting $meeting, string $dayName): string
    {
        $multiDay = [
            'daily_weekdays' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'],
            'tue_to_fri'     => ['Tuesday', 'Wednesday', 'Thursday', 'Friday'],
            'mon_thu'        => ['Monday', 'Thursday'],
            'mon_wed_fri'    => ['Monday', 'Wednesday', 'Friday'],
        ];

        $recurrence = $meeting->recurrence ?? '';
        if (! isset($multiDay[$recurrence])) {
            return $meeting->meeting_key;
        }

        if ($recurrence === 'daily_weekdays' && $dayName === 'Monday') {
            return $meeting->meeting_key;
        }

        return $meeting->meeting_key . '-' . strtolower(substr($dayName, 0, 3));
    }

    private function timeToMinutes(?string $time): ?int
    {
        if (! $time) return null;
        if (preg_match('/^(\d{1,2}):(\d{2})\s*(AM|PM)$/i', trim($time), $m)) {
            $h = (int) $m[1];
            $min = (int) $m[2];
            $period = strtoupper($m[3]);
            if ($period === 'PM' && $h !== 12) $h += 12;
            if ($period === 'AM' && $h === 12) $h = 0;
            return $h * 60 + $min;
        }
        if (preg_match('/^(\d{1,2}):(\d{2})$/', trim($time), $m)) {
            return (int) $m[1] * 60 + (int) $m[2];
        }
        return null;
    }
}
