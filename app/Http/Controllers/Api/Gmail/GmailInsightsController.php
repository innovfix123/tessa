<?php

namespace App\Http\Controllers\Api\Gmail;

use App\Http\Controllers\Controller;
use App\Models\GmailInsight;
use App\Services\GmailInsightsService;
use App\Services\TessaTaskService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Dashboard "important email" notifications. Mirrors SlackInsightsController but
 * personal-only — every Gmail insight belongs solely to its inbox owner, so each
 * action is scoped by user_id with no shared/audience handling.
 */
class GmailInsightsController extends Controller
{
    /** List the current user's Gmail insights for the dashboard (default) or history (?archive=1). */
    public function index(Request $request): JsonResponse
    {
        $userId  = (int) $request->user()->id;
        $archive = $request->boolean('archive');
        $now     = Carbon::now()->toDateTimeString();

        $q = GmailInsight::where('user_id', $userId);

        if (! $archive) {
            $q->whereIn('status', ['new', 'seen'])
                ->where(function ($w) use ($now) {
                    $w->whereNull('snooze_until')->orWhere('snooze_until', '<=', $now);
                });

            $days = (int) config('gmail_insights.dashboard_days', 7);
            if ($days > 0) {
                $q->where('created_at', '>=', Carbon::now()->subDays($days)->toDateTimeString());
            }

            // Per-recipient relevance filter (dashboard only; archive stays full).
            $this->applyRelevanceFilter($q, $userId);
        }

        $insights = $q->orderByRaw("FIELD(priority, 'urgent', 'high', 'medium', 'low')")
            ->orderByDesc('received_at')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return response()->json([
            'ok'       => true,
            'insights' => $insights,
            'counts'   => ['total' => $insights->count()],
        ]);
    }

    /**
     * Narrow the dashboard query to the user's configured relevance filter:
     * category IN allowed OR sender matches a configured pattern. No-op for
     * users without a filter (they see all their important emails).
     */
    private function applyRelevanceFilter($q, int $userId): void
    {
        $f = GmailInsightsService::filterFor($userId);
        if ($f === null) {
            return;
        }

        $cats     = (array) ($f['categories'] ?? []);
        $patterns = GmailInsightsService::readSenderPatternsFor($userId);
        if (! $cats && ! $patterns) {
            return; // safety net: an empty filter shows everything rather than nothing
        }

        $q->where(function ($w) use ($cats, $patterns) {
            $started = false;
            if ($cats) {
                $w->whereIn('category', $cats);
                $started = true;
            }
            foreach ($patterns as $p) {
                $w->{$started ? 'orWhere' : 'where'}('sender', 'like', '%' . $p . '%');
                $started = true;
            }
        });
    }

    /** Manually trigger a fetch+classify for the current user (needs a live Gmail connection). */
    public function scan(Request $request, GmailInsightsService $service): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasGoogleConnection()) {
            return response()->json(['error' => 'Gmail not connected. Go to Profile to connect.'], 403);
        }

        $res = $service->syncForUser($user);

        return response()->json(['ok' => true, 'new_insights' => $res['created'], 'error' => $res['error']]);
    }

    /** Mark an insight seen / dismissed (Ignore). */
    public function update(Request $request, int $id): JsonResponse
    {
        $request->validate(['status' => 'required|in:seen,actioned,dismissed']);

        $insight = $this->ownedOr404($id, (int) $request->user()->id);
        $insight->update(['status' => $request->input('status')]);

        return response()->json(['ok' => true, 'insight' => $insight->fresh()]);
    }

    /** Clear the current user's Gmail archive — hard delete (every row is personal). */
    public function clear(Request $request): JsonResponse
    {
        $deleted = GmailInsight::where('user_id', (int) $request->user()->id)->delete();

        return response()->json(['ok' => true, 'deleted' => $deleted]);
    }

    /** Snooze (Set Reminder) until a future datetime. */
    public function snooze(Request $request, int $id): JsonResponse
    {
        $request->validate(['until' => 'required|date|after:now']);

        $insight = $this->ownedOr404($id, (int) $request->user()->id);
        $insight->update(['snooze_until' => Carbon::parse($request->input('until')), 'status' => 'seen']);

        return response()->json(['ok' => true, 'insight' => $insight->fresh()]);
    }

    /** Add to Task: spin up a Tessa task from the email, assigned to the owner. */
    public function createTask(Request $request, int $id): JsonResponse
    {
        $request->validate(['priority' => 'sometimes|in:low,medium,high,urgent']);

        $user    = $request->user();
        $insight = $this->ownedOr404($id, (int) $user->id);

        if ($insight->task_id) {
            return response()->json(['error' => 'Task already created for this email'], 422);
        }

        $title    = $insight->subject ?: 'Email follow-up';
        $body     = trim(((string) ($insight->summary ?? '')) . ($insight->sender ? "\n\nFrom: {$insight->sender}" : ''));
        $priority = $request->input('priority', $insight->priority);

        $task = app(TessaTaskService::class)->createAndNotify(
            $user,
            (int) $user->id, // the email is the user's own — the task is for themselves
            $title,
            $body,
            $priority,
            null
        );

        $insight->update(['status' => 'actioned', 'task_id' => $task->id]);

        return response()->json(['ok' => true, 'task' => $task, 'insight' => $insight->fresh()], 201);
    }

    /** Bulk mark all "new" insights as "seen" for the current user. */
    public function markAllSeen(Request $request): JsonResponse
    {
        $count = GmailInsight::where('user_id', $request->user()->id)
            ->where('status', 'new')
            ->update(['status' => 'seen']);

        return response()->json(['ok' => true, 'updated' => $count]);
    }

    private function ownedOr404(int $id, int $userId): GmailInsight
    {
        $insight = GmailInsight::findOrFail($id);
        if ((int) $insight->user_id !== $userId) {
            abort(404);
        }

        return $insight;
    }
}
