<?php

namespace App\Services;

use App\Models\LogEntry;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Scans a user's OWN Slack messages (forward-only, from when they enabled the
 * feature) and records the meaningful ones into their Logs timeline.
 *
 * Cost control:
 *  - Only the user's own messages are fetched (`from:` scope).
 *  - A per-user cursor (last processed Slack ts) guarantees each message is
 *    evaluated exactly once — the cursor advances past skipped junk too.
 *  - Each run is capped so a quiet-then-busy gap can't spike the AI bill.
 */
class SlackLogScanService
{
    /** Max messages turned into AI calls per user per run. */
    private const MAX_PER_RUN = 40;

    /** How many search results to pull (newest first) before filtering. */
    private const SEARCH_COUNT = 100;

    /**
     * Only ever look at messages from roughly the last interval. The cron runs
     * every 15 min; the 1-minute overlap prevents boundary gaps and is absorbed
     * by the cursor + unique-index dedupe. Messages older than this are never
     * processed (so a missed run doesn't pull a big backlog or spike cost).
     */
    private const LOOKBACK_MINUTES = 16;

    /** When one of your messages is logged, also pull replies within this window. */
    private const SESSION_WINDOW_SECONDS = 1800;

    /** Max messages (yours + replies) included in a single captured exchange. */
    private const MAX_SESSION_MSGS = 8;

    public function scanUser(User $user): array
    {
        $result = ['scanned' => 0, 'created' => 0, 'skipped' => 0];

        // Connected = syncing. No separate opt-in toggle.
        if (! $user->hasSlackConnection() || ! $user->slack_user_id) {
            return $result;
        }

        $cursor = (float) ($user->logs_slack_cursor ?: 0);
        if ($cursor <= 0) {
            // No anchor yet — set it to now so we only ever capture future messages.
            $user->forceFill(['logs_slack_cursor' => sprintf('%.6f', microtime(true))])->save();

            return $result;
        }

        // Bound this run to the last ~15 minutes. The floor is the later of the
        // cursor and the window start, so we never reach back further than one
        // interval even if the cursor is stale (missed run / downtime).
        $windowStart = microtime(true) - (self::LOOKBACK_MINUTES * 60);
        $floor = max($cursor, $windowStart);

        // `after:` only supports day granularity, so anchor a day early and do the
        // precise > floor comparison in PHP.
        $afterDate = Carbon::createFromTimestamp($floor, 'Asia/Kolkata')->subDay()->format('Y-m-d');
        $query = 'from:<@' . $user->slack_user_id . '> after:' . $afterDate;

        try {
            $slack = SlackUserService::forUser($user);
            $response = $slack->searchMessages($query, self::SEARCH_COUNT, 1, 'timestamp', 'desc');
        } catch (\Throwable $e) {
            Log::warning('SlackLogScanService: search failed', [
                'user_id' => $user->id,
                'message' => $e->getMessage(),
            ]);

            return $result;
        }

        $matches = $response['messages']['matches'] ?? [];

        // Keep only the user's own messages inside the window, oldest-first.
        $candidates = [];
        foreach ($matches as $m) {
            $ts = (string) ($m['ts'] ?? '');
            if ($ts === '' || (float) $ts <= $floor) {
                continue;
            }
            if (($m['user'] ?? null) !== $user->slack_user_id) {
                continue;
            }
            $candidates[] = $m;
        }

        usort($candidates, fn ($a, $b) => ((float) $a['ts']) <=> ((float) $b['ts']));
        if (count($candidates) > self::MAX_PER_RUN) {
            $candidates = array_slice($candidates, 0, self::MAX_PER_RUN);
        }

        if (empty($candidates)) {
            return $result;
        }

        $ai = new TessaAIService;
        $maxTs = $floor;
        // Messages already folded into a captured exchange — skip them as anchors.
        $absorbedUpTo = $floor;
        $nameCache = [];

        foreach ($candidates as $m) {
            $ts = (string) $m['ts'];
            if ((float) $ts <= $absorbedUpTo) {
                continue;
            }
            $result['scanned']++;

            $channelId = $m['channel']['id'] ?? null;
            $myText = $this->cleanSlackText((string) ($m['text'] ?? ''));

            // Pull your message plus any replies that follow it in that conversation.
            $session = $this->fetchSession($slack, $channelId, $ts);
            $sessionMax = (float) $ts;
            foreach ($session as $sm) {
                $sessionMax = max($sessionMax, (float) $sm['ts']);
            }
            $maxTs = max($maxTs, $sessionMax);
            $absorbedUpTo = max($absorbedUpTo, $sessionMax);

            // Skip duplicates defensively (unique index also protects this).
            if (LogEntry::where('user_id', $user->id)->where('slack_ts', $ts)->exists()) {
                continue;
            }

            $hasOther = false;
            foreach ($session as $sm) {
                if (($sm['user'] ?? null) !== $user->slack_user_id) {
                    $hasOther = true;
                    break;
                }
            }

            if ($hasOther && count($session) > 1) {
                $transcript = $this->buildTranscript($session, $user, $nameCache);
                if ($transcript === '') {
                    $result['skipped']++;
                    continue;
                }
                $analysis = $ai->analyzeLogConversation($transcript, 'logs_slack', $user->id);
            } else {
                if ($myText === '') {
                    $result['skipped']++;
                    continue;
                }
                $analysis = $ai->analyzeLogEntry($myText, 'logs_slack', $user->id);
            }

            if (! ($analysis['log'] ?? true)) {
                $result['skipped']++;
                continue;
            }

            $when = Carbon::createFromTimestamp((float) $ts, 'UTC');

            $entry = new LogEntry;
            $entry->user_id = $user->id;
            $entry->content = $analysis['content'] ?? $myText;
            $entry->category = $analysis['category'] ?? LogEntry::CATEGORY_NOTE;
            $entry->source = LogEntry::SOURCE_SLACK;
            $entry->slack_ts = $ts;
            $entry->slack_permalink = $m['permalink'] ?? null;
            $entry->created_at = $when;
            $entry->updated_at = $when;
            $entry->save();

            $result['created']++;
        }

        // Advance the cursor past everything we evaluated so junk is never re-scanned.
        $user->forceFill(['logs_slack_cursor' => sprintf('%.6f', $maxTs)])->save();

        return $result;
    }

    /**
     * Fetch the user's anchor message plus the messages that follow it in the
     * same conversation within the session window. Returns oldest-first rows of
     * ['user', 'ts', 'text']. Falls back to empty on error (caller logs the
     * single message instead).
     *
     * @return array<int, array{user: ?string, ts: string, text: string}>
     */
    private function fetchSession(SlackUserService $slack, ?string $channelId, string $anchorTs): array
    {
        if (! $channelId) {
            return [];
        }

        try {
            $latest = sprintf('%.6f', (float) $anchorTs + self::SESSION_WINDOW_SECONDS);
            $resp = $slack->getHistoryWindow($channelId, $anchorTs, $latest, self::MAX_SESSION_MSGS + 2);
            $msgs = $resp['messages'] ?? [];

            $out = [];
            foreach ($msgs as $mm) {
                if (($mm['type'] ?? 'message') !== 'message' || ! empty($mm['subtype'])) {
                    continue;
                }
                $out[] = [
                    'user' => $mm['user'] ?? null,
                    'ts' => (string) ($mm['ts'] ?? ''),
                    'text' => (string) ($mm['text'] ?? ''),
                ];
            }

            usort($out, fn ($a, $b) => ((float) $a['ts']) <=> ((float) $b['ts']));
            if (count($out) > self::MAX_SESSION_MSGS) {
                $out = array_slice($out, 0, self::MAX_SESSION_MSGS);
            }

            return $out;
        } catch (\Throwable $e) {
            Log::warning('SlackLogScanService: session fetch failed', [
                'channel' => $channelId,
                'message' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Render a session into a "Speaker: text" transcript ("You" for the owner).
     */
    private function buildTranscript(array $session, User $user, array &$nameCache): string
    {
        $lines = [];
        foreach ($session as $sm) {
            $text = $this->cleanSlackText((string) ($sm['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $uid = $sm['user'] ?? null;
            $name = ($uid === $user->slack_user_id) ? 'You' : $this->resolveName($uid, $nameCache);
            $lines[] = $name.': '.$text;
        }

        return implode("\n", $lines);
    }

    /**
     * Resolve a Slack user id to a first name via the users table (cached).
     */
    private function resolveName(?string $slackUserId, array &$cache): string
    {
        if (! $slackUserId) {
            return 'Teammate';
        }
        if (isset($cache[$slackUserId])) {
            return $cache[$slackUserId];
        }

        $name = User::where('slack_user_id', $slackUserId)->value('name');
        $first = $name ? (explode(' ', trim($name))[0] ?: 'Teammate') : 'Teammate';

        return $cache[$slackUserId] = $first;
    }

    /**
     * Strip Slack markup so the AI and the stored entry read as plain text.
     */
    private function cleanSlackText(string $text): string
    {
        // <https://x|label> -> label ; <https://x> -> https://x
        $text = preg_replace('/<(https?:\/\/[^|>]+)\|([^>]+)>/', '$2', $text);
        $text = preg_replace('/<(https?:\/\/[^>]+)>/', '$1', $text);
        // <@U123|name> / <@U123> -> @name / (someone)
        $text = preg_replace('/<@[A-Z0-9]+\|([^>]+)>/', '@$1', $text);
        $text = preg_replace('/<@[A-Z0-9]+>/', '@someone', $text);
        // <#C123|channel> -> #channel
        $text = preg_replace('/<#[A-Z0-9]+\|([^>]+)>/', '#$1', $text);
        // <!here> / <!channel> -> @here / @channel
        $text = preg_replace('/<!([a-z]+)>/', '@$1', $text);
        // Unescape Slack HTML entities.
        $text = str_replace(['&amp;', '&lt;', '&gt;'], ['&', '<', '>'], $text);

        return trim($text);
    }
}
