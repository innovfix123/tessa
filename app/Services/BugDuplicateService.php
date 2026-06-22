<?php

namespace App\Services;

use App\Models\Bug;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * AI-driven duplicate bug clustering.
 *
 * Approach: pull all active (non-closed) bugs for a project, send a compact
 * id+title+description excerpt list to OpenRouter (Claude Haiku — cheap +
 * fast, this is a classification task not a creative one), parse the cluster
 * response, and write a shared `duplicate_group_id` UUID onto every bug in
 * a cluster of size ≥ 2. Singletons get NULL so the indicator stays clean.
 *
 * Re-running is idempotent: every active bug for the project has its group
 * id cleared first, then re-set from the new clusters. Bugs in closed/
 * verified status keep whatever group id they already have — those are
 * settled, and re-clustering them would just churn the field.
 */
class BugDuplicateService
{
    private const OPENROUTER_URL = 'https://openrouter.ai/api/v1/chat/completions';

    // Sonnet 4.6 — Haiku 4.5 missed obvious literally-identical duplicate
    // pairs once the candidate list grew past ~250 items, and also over-
    // clustered tagged task-series ([CALLS-01]…[CALLS-05]) as duplicates of
    // each other. Sonnet handles both the long-list recall and the "same
    // module ≠ same bug" distinction much better.
    // Moved to gemini-2.5-flash on 2026-06-04 (two-tier policy: only
    // OCR/insights/extraction stay premium; everything else → Flash). Was
    // claude-sonnet-4-6.
    private const MODEL = 'google/gemini-2.5-flash';

    private const ACTIVE_STATUSES = [
        Bug::STATUS_OPEN,
        Bug::STATUS_IN_PROGRESS,
        Bug::STATUS_FIXED,
    ];

    // Cap per-project bug list sent to the model. Sized to cover the largest
    // active project (Hima ~ 433 bugs as of 2026-05-21) in a single call —
    // chunking risks splitting a genuine duplicate pair across calls and
    // hiding it from the AI. Claude Haiku 4.5 has a 200K context, so even at
    // ~600 chars/bug after truncation this leaves ~5x headroom for the
    // system prompt and response.
    private const MAX_BUGS_PER_CALL = 500;

    // Hard cap on per-bug excerpt length so the prompt stays bounded even
    // when reporters paste 5kb of stack trace into the description.
    private const EXCERPT_CHARS = 240;

    private string $apiKey;

    public function __construct()
    {
        $this->apiKey = (string) config('services.openrouter.api_key');
    }

    /**
     * Detect duplicate clusters and persist group ids.
     *
     * @return array{processed:int, groups:int, duplicates:int, skipped:string|null}
     */
    public function detectAndStore(?int $projectId = null): array
    {
        if ($this->apiKey === '') {
            Log::warning('BugDuplicateService: OPENROUTER_API_KEY not configured');

            return ['processed' => 0, 'groups' => 0, 'duplicates' => 0, 'skipped' => 'no_api_key'];
        }

        $query = Bug::query()->whereIn('status', self::ACTIVE_STATUSES);
        if ($projectId !== null) {
            $query->where('project_id', $projectId);
        }
        $bugs = $query
            ->orderByDesc('created_at')
            ->limit(self::MAX_BUGS_PER_CALL)
            ->get(['id', 'project_id', 'title', 'description', 'steps_to_reproduce']);

        if ($bugs->count() < 2) {
            return ['processed' => $bugs->count(), 'groups' => 0, 'duplicates' => 0, 'skipped' => null];
        }

        $clusters = $this->aiCluster($bugs->all());
        $clusters = $this->sanitizeClusters($clusters, $bugs->pluck('id')->all());

        DB::transaction(function () use ($bugs, $clusters) {
            // Wipe old group ids for the bugs we just considered so re-runs
            // don't leave stale groupings around if the AI splits a cluster.
            Bug::whereIn('id', $bugs->pluck('id'))->update(['duplicate_group_id' => null]);

            foreach ($clusters as $clusterIds) {
                if (count($clusterIds) < 2) {
                    continue;
                }
                $groupId = (string) Str::uuid();
                Bug::whereIn('id', $clusterIds)->update(['duplicate_group_id' => $groupId]);
            }
        });

        $groupCount = count(array_filter($clusters, fn ($c) => count($c) >= 2));
        $dupCount = (int) array_sum(array_map(fn ($c) => count($c) >= 2 ? count($c) : 0, $clusters));

        return [
            'processed' => $bugs->count(),
            'groups' => $groupCount,
            'duplicates' => $dupCount,
            'skipped' => null,
        ];
    }

    /**
     * @param  array<int, Bug>  $bugs
     * @return array<int, array<int, int>>  Each inner array is a cluster of bug IDs.
     */
    private function aiCluster(array $bugs): array
    {
        $items = [];
        foreach ($bugs as $b) {
            $items[] = [
                'id' => (int) $b->id,
                'title' => $this->compact((string) $b->title, 120),
                'description' => $this->compact((string) $b->description, self::EXCERPT_CHARS),
                'steps' => $this->compact((string) $b->steps_to_reproduce, self::EXCERPT_CHARS),
            ];
        }

        $systemPrompt = <<<'SYS'
You group software bug reports into clusters of duplicates.

Definition — two bugs are duplicates ONLY when they describe the SAME underlying defect: same broken behavior, same trigger, same affected feature. The wording can be very different, and the reporters can be different people. What matters is whether fixing one bug would close the other without any extra work.

Be conservative. False-positive duplicate flags waste triage time and make people stop trusting the indicator. When in doubt, do not cluster.

Rules — DO cluster:
- Identical or near-identical titles describing the same symptom (e.g. "Login broken on Safari" + "Cannot log in on Safari iOS").
- Different wording, same root cause (e.g. "App crashes when uploading >5MB image" + "Image upload fails for large files").

Rules — DO NOT cluster:
- Bugs from the same module/area but with different symptoms (e.g. [CALLS-01] "Why no calls diagnostic" + [CALLS-02] "FCM token refresh" — both about calls, but different defects).
- Bugs from the same task series tagged consecutively (e.g. [B001], [B002], [B003] are typically separate items, not duplicates of each other — unless the titles describe the same exact issue).
- Sub-tasks or follow-ups of the same parent feature.
- Bugs that share a keyword but describe unrelated behavior (e.g. "login" appearing in OAuth bug and password-reset bug).

Return ONLY valid JSON, no markdown fences, no commentary:
{"clusters": [[id, id, ...], [id, id, ...]]}

Each inner array must contain at least 2 bug IDs. Do not emit singleton clusters. Do not include any bug ID more than once across all clusters. If no duplicates exist, return {"clusters": []}.
SYS;

        $userMsg = "Bugs to cluster (JSON list):\n"
            .json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ."\n\nReturn ONLY the JSON object {\"clusters\": [...]} — no analysis text, no markdown fences, no commentary. The first character of your response must be `{` and the last must be `}`.";

        $payload = [
            'model' => self::MODEL,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userMsg],
            ],
            'temperature' => 0.2,
            // Force JSON response shape. Sonnet was emitting a chain-of-
            // thought analysis in natural language otherwise (correct
            // conclusions but unparseable), even with explicit instructions.
            'response_format' => ['type' => 'json_object'],
        ];

        try {
            $client = new Client([
                'timeout' => 120,
                'connect_timeout' => 15,
            ]);

            $response = $client->post(self::OPENROUTER_URL, [
                'headers' => [
                    'Authorization' => 'Bearer '.$this->apiKey,
                    'Content-Type' => 'application/json',
                    'HTTP-Referer' => config('app.url', 'https://tessa.innovfix.ai'),
                    'X-Title' => 'Tessa Bug Duplicate Detection',
                ],
                'json' => $payload,
            ]);

            $body = json_decode((string) $response->getBody(), true);
            $content = $body['choices'][0]['message']['content'] ?? '';
            if (! is_string($content) || $content === '') {
                Log::warning('BugDuplicateService: empty AI response');

                return [];
            }
            // Strip any accidental ```json fences the model may add despite
            // the prompt asking for raw JSON.
            $content = trim($content);
            $content = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $content);

            $decoded = json_decode((string) $content, true);
            if (! is_array($decoded) || ! isset($decoded['clusters']) || ! is_array($decoded['clusters'])) {
                Log::warning('BugDuplicateService: unexpected AI response shape', ['content' => $content]);

                return [];
            }

            return $decoded['clusters'];
        } catch (\Throwable $e) {
            Log::warning('BugDuplicateService: AI call failed', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Ensure clusters only contain valid bug IDs from the candidate set, drop
     * singletons, and de-dupe IDs (a bug can only belong to one cluster).
     *
     * @param  array<int, mixed>  $clusters
     * @param  array<int, int>  $validIds
     * @return array<int, array<int, int>>
     */
    private function sanitizeClusters(array $clusters, array $validIds): array
    {
        $validSet = array_flip(array_map('intval', $validIds));
        $seen = [];
        $clean = [];
        foreach ($clusters as $cluster) {
            if (! is_array($cluster)) {
                continue;
            }
            $ids = [];
            foreach ($cluster as $id) {
                $id = (int) $id;
                if ($id <= 0 || ! isset($validSet[$id]) || isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $ids[] = $id;
            }
            if (count($ids) >= 2) {
                $clean[] = $ids;
            }
        }

        return $clean;
    }

    private function compact(string $text, int $maxChars): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags($text)) ?? '');
        if (mb_strlen($text) <= $maxChars) {
            return $text;
        }

        return mb_substr($text, 0, $maxChars - 1).'…';
    }
}
