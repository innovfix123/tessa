<?php

namespace App\Http\Controllers\Api\Slack;

use App\Http\Controllers\Controller;
use App\Models\SlackInsight;
use App\Models\SlackInsightUserState;
use App\Models\User;
use App\Services\SlackHuddleSyncService;
use App\Services\TessaTaskService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SlackInsightsController extends Controller
{
    /**
     * Trigger the meeting-notes pipeline: pull latest huddle notes and run the
     * AI extractor. Replaces the old DM/channel scan.
     */
    public function scan(Request $request, SlackHuddleSyncService $service): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasSlackConnection()) {
            return response()->json(['error' => 'Slack not connected. Go to Profile to connect.'], 403);
        }

        $result = $service->syncAll(callerUser: $user, attendanceOnly: true, withInsights: true);

        $insightsCreated = 0;
        foreach ($result['details'] ?? [] as $d) {
            $insightsCreated += (int) ($d['insights_created'] ?? 0);
        }

        return response()->json([
            'ok'                => true,
            'new_insights'      => $insightsCreated,
            'meetings_processed'=> ($result['synced'] ?? 0) + ($result['skipped'] ?? 0),
            'message'           => $result['message'] ?? null,
        ]);
    }

    /**
     * List insights for the dashboard cards (default) or for the history archive (?archive=1).
     *
     * Dashboard mode returns:
     *   - personal rows for me with status IN (new, seen) and snooze NULL/past
     *   - shared rows whose audience_user_ids contains me AND no active dismiss/snooze in user_state
     *
     * Archive mode returns everything I'm a recipient of, all statuses included.
     */
    public function index(Request $request): JsonResponse
    {
        $user    = $request->user();
        $userId  = (int) $user->id;
        $archive = $request->boolean('archive');
        // Archives view passes this so a "Clear history" (which dismisses rows)
        // visibly empties the list; plain archive mode still shows everything.
        $hideDismissed = $request->boolean('hide_dismissed');
        $now     = Carbon::now()->toDateTimeString();

        // Active insight types — `important` was retired; legacy rows of that
        // type stay in the DB but never surface anywhere.
        $activeTypes = ['action_item', 'reminder', 'follow_up', 'decision'];

        // Dashboard freshness cap: only show insights created in the last N days
        // (default 14). Older cards still appear in the Slack archive view
        // (?archive=1) — they're not deleted, just demoted off the live feed.
        // Set SLACK_INSIGHTS_DASHBOARD_DAYS in .env to override.
        $dashboardDays = (int) config('services.slack_insights.dashboard_days', 14);
        $freshFloor    = $dashboardDays > 0
            ? Carbon::now()->subDays($dashboardDays)->toDateTimeString()
            : null;

        // ----- Personal rows -----
        // Only meeting-note-sourced insights — legacy DM/channel-scan rows have
        // meeting_id NULL and must never surface on the dashboard or archive.
        $personalQ = SlackInsight::query()
            ->where('audience', 'personal')
            ->where('user_id', $userId)
            ->whereNotNull('meeting_id')
            ->whereIn('type', $activeTypes);

        if (! $archive) {
            $personalQ->whereIn('status', ['new', 'seen'])
                ->where(function ($q) use ($now) {
                    $q->whereNull('snooze_until')->orWhere('snooze_until', '<=', $now);
                });
            if ($freshFloor !== null) {
                $personalQ->where('created_at', '>=', $freshFloor);
            }
        }

        if ($archive && $hideDismissed) {
            $personalQ->where('status', '!=', 'dismissed');
        }

        $personal = $personalQ->with(['assignedBy:id,name', 'suggestedAssignee:id,name'])
            ->orderByRaw($this->priorityOrderExpr())
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        // ----- Shared rows -----
        // JSON snapshot stored ints; tolerate string-coerced ids as a fallback.
        $idAsInt    = json_encode((int) $userId);
        $idAsString = json_encode((string) $userId);

        $sharedQ = SlackInsight::query()
            ->where('audience', 'meeting')
            ->whereIn('type', $activeTypes)
            ->where(function ($q) use ($idAsInt, $idAsString) {
                $q->whereRaw('JSON_CONTAINS(audience_user_ids, ?)', [$idAsInt])
                  ->orWhereRaw('JSON_CONTAINS(audience_user_ids, ?)', [$idAsString]);
            });

        if (! $archive) {
            $sharedQ->whereNotExists(function ($q) use ($userId, $now) {
                $q->select(DB::raw(1))
                    ->from('slack_insight_user_state')
                    ->whereColumn('slack_insight_user_state.insight_id', 'slack_insights.id')
                    ->where('slack_insight_user_state.user_id', $userId)
                    ->where(function ($q2) use ($now) {
                        $q2->whereIn('status', ['dismissed', 'actioned'])
                            ->orWhere(function ($q3) use ($now) {
                                $q3->where('status', 'snoozed')->where('snooze_until', '>', $now);
                            });
                    });
            });
            if ($freshFloor !== null) {
                $sharedQ->where('created_at', '>=', $freshFloor);
            }
        }

        if ($archive && $hideDismissed) {
            $sharedQ->whereNotExists(function ($q) use ($userId) {
                $q->select(DB::raw(1))
                    ->from('slack_insight_user_state')
                    ->whereColumn('slack_insight_user_state.insight_id', 'slack_insights.id')
                    ->where('slack_insight_user_state.user_id', $userId)
                    ->where('status', 'dismissed');
            });
        }

        $shared = $sharedQ->with(['assignedBy:id,name', 'suggestedAssignee:id,name'])
            ->orderByRaw($this->priorityOrderExpr())
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        // Attach per-user state on shared rows (so frontend knows seen status, snooze, task_id)
        $stateMap = [];
        if ($shared->isNotEmpty()) {
            $states = SlackInsightUserState::query()
                ->whereIn('insight_id', $shared->pluck('id'))
                ->where('user_id', $userId)
                ->get();
            foreach ($states as $s) {
                $stateMap[$s->insight_id] = $s;
            }
        }

        $sharedOut = $shared->map(function ($i) use ($stateMap) {
            $arr = $i->toArray();
            $arr['my_state'] = isset($stateMap[$i->id]) ? $stateMap[$i->id]->toArray() : null;
            return $arr;
        });

        $merged = $personal->map(function ($i) {
            $arr = $i->toArray();
            $arr['my_state'] = null;
            return $arr;
        })->concat($sharedOut)
            ->sortByDesc(function ($i) {
                $rank = ['urgent' => 4, 'high' => 3, 'medium' => 2, 'low' => 1];
                $p = $rank[$i['priority']] ?? 0;
                return $p * 1000000 + strtotime((string) ($i['created_at'] ?? 'now'));
            })
            ->values();

        // Attach a viewer-specific meeting label to each card (e.g. "1:1 with
        // Bhuvan", "ai-intern huddle", or the scheduled meeting name). Resolve
        // every referenced user id once.
        $nameIds = [];
        foreach ($merged as $row) {
            foreach (($row['meeting_attendee_ids'] ?? []) as $aid) $nameIds[] = (int) $aid;
            if (! empty($row['suggested_assignee_id'])) $nameIds[] = (int) $row['suggested_assignee_id'];
            if (! empty($row['assigned_by_user_id']))   $nameIds[] = (int) $row['assigned_by_user_id'];
        }
        $nameMap = empty($nameIds)
            ? []
            : User::whereIn('id', array_values(array_unique($nameIds)))->pluck('name', 'id')->all();

        // DM huddles store the other person's Slack ID as their "channel name";
        // resolve those back to names so 1:1 huddle cards never show a raw "U…" code.
        $slackNameMap = User::whereNotNull('slack_user_id')->pluck('name', 'slack_user_id')->all();

        $merged = $merged->map(function ($row) use ($userId, $nameMap, $slackNameMap) {
            $row['meeting_label'] = $this->meetingLabelFor($row, $userId, $nameMap, $slackNameMap);

            // For the assigner's "things I delegated" view: surface the doer's
            // first name when the viewer isn't the doer. Null for the viewer's
            // own task or a shared/unassigned item.
            $doer = (int) ($row['suggested_assignee_id'] ?? 0);
            $row['delegated_to'] = ($doer && $doer !== $userId && isset($nameMap[$doer]))
                ? explode(' ', $nameMap[$doer])[0]
                : null;

            return $row;
        })->values();

        // Summary counts (for badge)
        $counts = [
            'total'     => $merged->count(),
            'personal'  => $personal->count(),
            'shared'    => $shared->count(),
        ];

        return response()->json([
            'ok'       => true,
            'insights' => $merged,
            'counts'   => $counts,
        ]);
    }

    /**
     * Build the viewer-specific meeting label shown on a suggestion card.
     *  - one_on_one → "1:1 with <the other participant>"
     *  - channel    → "<channel> huddle" (e.g. "ai-intern huddle")
     *  - group      → the parsed group heading, else "Group huddle"
     *  - scheduled / legacy (null) → the meeting title as-is
     */
    private function meetingLabelFor(array $row, int $viewerId, array $nameMap, array $slackNameMap = []): string
    {
        $title = trim((string) ($row['meeting_title'] ?? '')) ?: 'Huddle';

        switch ($row['meeting_kind'] ?? null) {
            case 'one_on_one':
                $other = $this->otherParticipantName($row, $viewerId, $nameMap);
                return $other !== null ? '1:1 with ' . $other : 'Unknown huddle';

            case 'channel':
                $channel = ltrim(trim((string) ($row['source_channel_name'] ?? '')), '#');
                // A DM huddle's "channel name" is the other person's Slack ID (e.g.
                // "U0EFJ9W43FX"), not a human channel name. Resolve it to that teammate —
                // via the Slack-ID map, then the AI-extracted attendees — and show a 1:1;
                // if the name is genuinely unknown, fall back to "Unknown huddle".
                if ($channel !== '' && preg_match('/^[UW][A-Z0-9]{6,}$/', $channel)) {
                    $other = $slackNameMap[$channel] ?? $this->otherParticipantName($row, $viewerId, $nameMap);
                    return $other !== null ? '1:1 with ' . $other : 'Unknown huddle';
                }
                return $channel !== '' ? $channel . ' huddle' : 'Group huddle';

            case 'group':
                return $title !== 'Huddle' ? $title : 'Group huddle';

            case 'scheduled':
            default:
                return $title;
        }
    }

    /**
     * Resolve the "other" participant's name for a 1:1/DM huddle: scan the
     * AI-extracted attendees, then the suggested assignee / assigner, for anyone
     * who isn't the viewer and resolves to a name. Returns null when none is known.
     */
    private function otherParticipantName(array $row, int $viewerId, array $nameMap): ?string
    {
        $candidates = array_map('intval', $row['meeting_attendee_ids'] ?? []);
        $candidates[] = (int) ($row['suggested_assignee_id'] ?? 0);
        $candidates[] = (int) ($row['assigned_by_user_id'] ?? 0);
        foreach (array_unique($candidates) as $cid) {
            if ($cid && $cid !== $viewerId && isset($nameMap[$cid])) {
                return $nameMap[$cid];
            }
        }
        return null;
    }

    /**
     * Update insight status (seen / actioned / dismissed).
     * For personal rows, mutates slack_insights.status.
     * For shared rows, upserts a per-user state row.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'status' => 'required|in:seen,actioned,dismissed',
        ]);

        $user    = $request->user();
        $userId  = (int) $user->id;
        $status  = $request->input('status');
        $insight = $this->insightVisibleTo($id, $userId);

        if ($insight->audience === 'personal') {
            $insight->update(['status' => $status]);
            return response()->json(['ok' => true, 'insight' => $insight->fresh(), 'my_state' => null]);
        }

        $state = SlackInsightUserState::updateOrCreate(
            ['insight_id' => $insight->id, 'user_id' => $userId],
            ['status'     => $status]
        );

        return response()->json(['ok' => true, 'insight' => $insight, 'my_state' => $state]);
    }

    /**
     * Clear the current user's live dashboard feed (non-destructive). Nothing is
     * ever hard-deleted — personal rows are marked dismissed and shared (meeting)
     * rows get a per-user dismissed state. The full history stays visible in the
     * Archives → Slack tab.
     */
    public function clear(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->id;

        $dismissedPersonal = SlackInsight::where('audience', 'personal')
            ->where('user_id', $userId)
            ->where('status', '!=', 'dismissed')
            ->update(['status' => 'dismissed']);

        $idAsInt    = json_encode((int) $userId);
        $idAsString = json_encode((string) $userId);
        $sharedIds = SlackInsight::where('audience', 'meeting')
            ->where(function ($q) use ($idAsInt, $idAsString) {
                $q->whereRaw('JSON_CONTAINS(audience_user_ids, ?)', [$idAsInt])
                  ->orWhereRaw('JSON_CONTAINS(audience_user_ids, ?)', [$idAsString]);
            })
            ->pluck('id');

        foreach ($sharedIds as $iid) {
            SlackInsightUserState::updateOrCreate(
                ['insight_id' => $iid, 'user_id' => $userId],
                ['status' => 'dismissed']
            );
        }

        return response()->json([
            'ok'                 => true,
            'dismissed_personal' => $dismissedPersonal,
            'dismissed_shared'   => $sharedIds->count(),
        ]);
    }

    /**
     * Snooze an insight for the current user. Validates `until` as a future datetime.
     * Personal: writes to slack_insights.snooze_until.
     * Shared:   writes per-user state row (status=snoozed, snooze_until=...).
     */
    public function snooze(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'until' => 'required|date|after:now',
        ]);

        $user    = $request->user();
        $userId  = (int) $user->id;
        $until   = Carbon::parse($request->input('until'));
        $insight = $this->insightVisibleTo($id, $userId);

        if ($insight->audience === 'personal') {
            $insight->update(['snooze_until' => $until, 'status' => 'seen']);
            return response()->json(['ok' => true, 'insight' => $insight->fresh(), 'my_state' => null]);
        }

        $state = SlackInsightUserState::updateOrCreate(
            ['insight_id' => $insight->id, 'user_id' => $userId],
            ['status' => 'snoozed', 'snooze_until' => $until]
        );

        return response()->json(['ok' => true, 'insight' => $insight, 'my_state' => $state]);
    }

    /**
     * Create a Tessa task from an insight. Caller may override `assigned_to`;
     * otherwise we default to the insight's suggested assignee or the current user.
     */
    public function createTask(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'assigned_to' => 'sometimes|exists:users,id',
            'priority'    => 'sometimes|in:low,medium,high,urgent',
        ]);

        $user    = $request->user();
        $userId  = (int) $user->id;
        $insight = $this->insightVisibleTo($id, $userId);

        // Already converted for this recipient?
        if ($insight->audience === 'personal' && $insight->task_id) {
            return response()->json(['error' => 'Task already created for this insight'], 422);
        }
        if ($insight->audience === 'meeting') {
            $existing = SlackInsightUserState::where('insight_id', $insight->id)
                ->where('user_id', $userId)
                ->whereNotNull('task_id')
                ->first();
            if ($existing) {
                return response()->json(['error' => 'Task already created for this insight'], 422);
            }
        }

        $assignedTo = (int) $request->input('assigned_to', $insight->suggested_assignee_id ?: $userId);
        $priority   = $request->input('priority', $insight->priority);
        $deadline   = $insight->due_date ? $insight->due_date->toDateString() : null;

        $taskService = app(TessaTaskService::class);
        $task = $taskService->createAndNotify(
            $user,
            $assignedTo,
            $insight->title,
            $insight->summary ?? '',
            $priority,
            $deadline
        );

        if ($insight->audience === 'personal') {
            $insight->update(['status' => 'actioned', 'task_id' => $task->id]);
            $myState = null;
        } else {
            $myState = SlackInsightUserState::updateOrCreate(
                ['insight_id' => $insight->id, 'user_id' => $userId],
                ['status' => 'actioned', 'task_id' => $task->id]
            );
        }

        return response()->json([
            'ok'       => true,
            'task'     => $task,
            'insight'  => $insight->fresh(),
            'my_state' => $myState,
        ], 201);
    }

    /**
     * Mark all "new" personal insights as "seen" for the current user.
     * (Shared insights don't have a per-user "new" status; nothing to bulk-update.)
     */
    public function markAllSeen(Request $request): JsonResponse
    {
        $count = SlackInsight::where('user_id', $request->user()->id)
            ->where('audience', 'personal')
            ->where('status', 'new')
            ->update(['status' => 'seen']);

        return response()->json(['ok' => true, 'updated' => $count]);
    }

    /**
     * Resolve an insight that the current user is a recipient of (personal owner OR
     * a meeting attendee for shared rows). 404 otherwise.
     */
    private function insightVisibleTo(int $id, int $userId): SlackInsight
    {
        $insight = SlackInsight::findOrFail($id);

        if ($insight->audience === 'personal') {
            if ((int) $insight->user_id !== $userId) abort(404);
            return $insight;
        }

        $ids = is_array($insight->audience_user_ids) ? $insight->audience_user_ids : [];
        $hit = false;
        foreach ($ids as $candidate) {
            if ((int) $candidate === $userId) { $hit = true; break; }
        }
        if (! $hit) abort(404);

        return $insight;
    }

    private function priorityOrderExpr(): string
    {
        return "FIELD(priority, 'urgent', 'high', 'medium', 'low')";
    }
}
