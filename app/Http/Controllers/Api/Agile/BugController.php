<?php

namespace App\Http\Controllers\Api\Agile;

use App\Http\Controllers\Controller;
use App\Models\Bug;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\AgileService;
use App\Services\BugDuplicateService;
use App\Services\ProjectRoleService;
use App\Services\SlackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class BugController extends Controller
{
    // Whitelist of developer user IDs who should receive a Slack DM when a
    // bug is assigned to them. Anyone else assigned a bug is silently
    // skipped — Yuvanesh asked for this so notifications stay scoped to
    // the actual dev team and don't spam PMs/QAs who occasionally hold a
    // bug for triage.
    //   12 - Tamil Arasan      35 - Rishabh
    //   37 - Perumal           38 - Maari (Mari Muthu)
    //   42 - Sneha Prathap
    private const BUG_NOTIFY_USER_IDS = [12, 35, 37, 38, 42];

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! ProjectRoleService::hasFeature($user->role, 'agile')) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $query = Bug::with(['assignee:id,name', 'reporter:id,name', 'epic:id,title', 'story:id,title', 'sprint:id,name', 'labels', 'attachments'])
            ->orderByDesc('created_at');

        AgileService::scopeToAllowedProjects($query, $user);

        // Backlog visibility is intentionally NOT restricted to own items here.
        // Yuvanesh asked for a "filter by developer" dropdown on the Backlog tab,
        // which is only meaningful if every agile role (developers + QAs alike)
        // can see the full bug list to filter through. Scoping back to own/reported
        // would leave the dropdown showing just the dev themselves. The Sprint
        // Board view (SprintController::board) still scopes per-user — that's the
        // focused-work surface; this listing endpoint is the triage surface.

        if ($request->query('project_id')) {
            $query->where('project_id', (int) $request->query('project_id'));
        }
        if ($request->query('sprint_id')) {
            $query->where('sprint_id', (int) $request->query('sprint_id'));
        }
        if ($request->query('story_id')) {
            $query->where('story_id', (int) $request->query('story_id'));
        }
        if ($request->query('status') && in_array($request->query('status'), Bug::STATUSES, true)) {
            $query->where('status', $request->query('status'));
        }
        if ($request->query('severity') && in_array($request->query('severity'), Bug::SEVERITIES, true)) {
            $query->where('severity', $request->query('severity'));
        }
        if ($request->query('assignee_id')) {
            $query->where('assignee_id', (int) $request->query('assignee_id'));
        }

        $bugs = $query->get()->map(fn ($b) => $this->normalizeBug($b));

        return response()->json(['ok' => true, 'bugs' => $bugs]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! ProjectRoleService::hasFeature($user->role, 'agile')) {
            return response()->json(['error' => 'Forbidden'], 403);
        }
        if (! ProjectRoleService::canCrudAgileBugs($user->role)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'steps_to_reproduce' => 'nullable|string',
            'epic_id' => 'nullable|exists:epics,id',
            'story_id' => 'nullable|exists:stories,id',
            'sprint_id' => 'nullable|exists:sprints,id',
            'assignee_id' => 'nullable|exists:users,id',
            'project_id' => 'nullable|exists:projects,id',
            'severity' => 'sometimes|in:'.implode(',', Bug::SEVERITIES),
            'priority' => 'sometimes|in:'.implode(',', Bug::PRIORITIES),
            'story_points' => 'nullable|integer|min:1|max:21',
            'environment' => 'nullable|in:'.implode(',', Bug::ENVIRONMENTS),
            'screenshot' => 'nullable|file|max:51200',
            'attachments' => 'nullable|array|max:10',
            'attachments.*' => 'file|max:51200',
            'label_ids' => 'nullable|array',
            'label_ids.*' => 'exists:labels,id',
        ]);

        $labelIds = $validated['label_ids'] ?? [];
        unset($validated['label_ids']);

        // Legacy single-screenshot field still supported (one image inline)
        $screenshotPath = null;
        if ($request->hasFile('screenshot')) {
            $screenshotPath = $request->file('screenshot')->store('bug-screenshots', 'public');
        }
        unset($validated['screenshot'], $validated['attachments']);

        $bug = Bug::create([
            ...$validated,
            'screenshot_path' => $screenshotPath,
            'reporter_id' => $user->id,
            'status' => Bug::STATUS_OPEN,
            'created_by' => $user->id,
        ]);

        // New multi-attachment uploads
        $this->saveAttachments($request, $bug, $user);

        if ($labelIds) {
            $bug->labels()->sync($labelIds);
        }

        $bug->load(['assignee:id,name', 'reporter:id,name', 'epic:id,title', 'story:id,title', 'sprint:id,name', 'labels', 'attachments']);
        ActivityLogService::log($user->id, 'bug_created', "{$user->name} reported bug: {$bug->title}", 'bug', $bug->id, ['severity' => $bug->severity]);

        $this->notifyAssigneeOfAssignment($bug, $user, null);

        return response()->json(['ok' => true, 'bug' => $this->normalizeBug($bug)], 201);
    }

    public function update(Request $request, Bug $bug): JsonResponse
    {
        $user = $request->user();
        if (! ProjectRoleService::hasFeature($user->role, 'agile')) {
            return response()->json(['error' => 'Forbidden'], 403);
        }
        if (! AgileService::canUpdateItem($user, $bug)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'steps_to_reproduce' => 'nullable|string',
            'epic_id' => 'nullable|exists:epics,id',
            'story_id' => 'nullable|exists:stories,id',
            'sprint_id' => 'nullable|exists:sprints,id',
            'assignee_id' => 'nullable|exists:users,id',
            'status' => 'sometimes|in:'.implode(',', Bug::STATUSES),
            'severity' => 'sometimes|in:'.implode(',', Bug::SEVERITIES),
            'priority' => 'sometimes|in:'.implode(',', Bug::PRIORITIES),
            'story_points' => 'nullable|integer|min:1|max:21',
            'environment' => 'nullable|in:'.implode(',', Bug::ENVIRONMENTS),
            'screenshot' => 'nullable|file|max:51200',
            'screenshot_clear' => 'sometimes|boolean',
            'attachments' => 'nullable|array|max:10',
            'attachments.*' => 'file|max:51200',
            'attachment_remove_ids' => 'nullable|array',
            'attachment_remove_ids.*' => 'integer|exists:bug_attachments,id',
            'label_ids' => 'nullable|array',
            'label_ids.*' => 'exists:labels,id',
        ]);

        if (isset($validated['label_ids'])) {
            $bug->labels()->sync($validated['label_ids']);
            unset($validated['label_ids']);
        }

        $clearScreenshot = $request->boolean('screenshot_clear');
        if ($request->hasFile('screenshot')) {
            if ($bug->screenshot_path) {
                Storage::disk('public')->delete($bug->screenshot_path);
            }
            $validated['screenshot_path'] = $request->file('screenshot')->store('bug-screenshots', 'public');
        } elseif ($clearScreenshot && $bug->screenshot_path) {
            Storage::disk('public')->delete($bug->screenshot_path);
            $validated['screenshot_path'] = null;
        }
        unset($validated['screenshot'], $validated['screenshot_clear'], $validated['attachments'], $validated['attachment_remove_ids']);

        // Multi-attachment ops: save new uploads, remove any IDs the client asked to drop
        $this->saveAttachments($request, $bug, $user);
        $this->removeAttachments($request, $bug);

        $oldStatus = $bug->status;
        $oldAssigneeId = $bug->assignee_id;

        // Set resolved_at when bug is closed/fixed
        if (isset($validated['status']) && in_array($validated['status'], [Bug::STATUS_CLOSED, Bug::STATUS_WONT_FIX], true) && ! $bug->resolved_at) {
            $validated['resolved_at'] = now();
        }

        $bug->update($validated);

        if (isset($validated['status']) && $validated['status'] !== $oldStatus) {
            ActivityLogService::log($user->id, 'bug_status_changed', "{$user->name} moved bug \"{$bug->title}\" from {$oldStatus} to {$validated['status']}", 'bug', $bug->id, ['from' => $oldStatus, 'to' => $validated['status']]);
            $this->notifyQaIfFixed($bug, $oldStatus, $validated['status'], $user);
        } else {
            ActivityLogService::log($user->id, 'bug_updated', "{$user->name} updated bug: {$bug->title}", 'bug', $bug->id);
        }

        // Re-assignment notification — only when assignee_id was actually
        // included in the request AND it's a real change to a real person.
        // array_key_exists (not isset) so an explicit `null` (unassign) is
        // still detected; the helper short-circuits on null/self/no-change.
        if (array_key_exists('assignee_id', $validated) && (int) ($validated['assignee_id'] ?? 0) !== (int) ($oldAssigneeId ?? 0)) {
            $this->notifyAssigneeOfAssignment($bug, $user, $oldAssigneeId);
        }

        $bug->load(['assignee:id,name', 'reporter:id,name', 'epic:id,title', 'story:id,title', 'sprint:id,name', 'labels', 'attachments']);

        return response()->json(['ok' => true, 'bug' => $this->normalizeBug($bug->fresh()->load(['attachments']))]);
    }

    /**
     * Find possible duplicates for a candidate bug title.
     *
     * Called by the "Report Bug" modal as the reporter types: surfaces up to
     * 5 still-open bugs in the same project whose titles are at least ~55%
     * similar (PHP similar_text). Verified/closed/wont_fix are excluded — those
     * are settled and shouldn't block a new report.
     */
    public function checkDuplicates(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! ProjectRoleService::hasFeature($user->role, 'agile')) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'title' => 'required|string|min:4|max:255',
            'project_id' => 'nullable|integer|exists:projects,id',
            'exclude_id' => 'nullable|integer|exists:bugs,id',
        ]);

        $title = trim($validated['title']);
        $openStatuses = [Bug::STATUS_OPEN, Bug::STATUS_IN_PROGRESS, Bug::STATUS_FIXED];

        $query = Bug::query()
            ->with(['assignee:id,name', 'project:id,name'])
            ->whereIn('status', $openStatuses)
            ->orderByDesc('created_at')
            ->limit(150);

        AgileService::scopeToAllowedProjects($query, $user);

        if (! empty($validated['project_id'])) {
            $query->where('project_id', (int) $validated['project_id']);
        }
        if (! empty($validated['exclude_id'])) {
            $query->where('id', '!=', (int) $validated['exclude_id']);
        }

        $candidates = $query->get();
        $needleLower = mb_strtolower($title);

        $scored = [];
        foreach ($candidates as $b) {
            $haystackLower = mb_strtolower((string) $b->title);
            $percent = 0.0;
            similar_text($needleLower, $haystackLower, $percent);

            // Boost when the new title is a substring of an existing one (or vice versa)
            // — common when reporters add extra detail to a known issue.
            if ($percent < 70.0 && $needleLower !== '' && $haystackLower !== '' &&
                (str_contains($haystackLower, $needleLower) || str_contains($needleLower, $haystackLower))) {
                $percent = max($percent, 80.0);
            }

            if ($percent >= 55.0) {
                $scored[] = [
                    'id' => $b->id,
                    'title' => $b->title,
                    'status' => $b->status,
                    'severity' => $b->severity,
                    'priority' => $b->priority,
                    'projectId' => $b->project_id,
                    'projectName' => $b->project?->name ?? '',
                    'assigneeId' => $b->assignee_id,
                    'assigneeName' => $b->assignee?->name ?? '',
                    'createdAt' => $b->created_at?->toIso8601String(),
                    'similarity' => (int) round($percent),
                ];
            }
        }

        usort($scored, fn ($a, $b) => $b['similarity'] <=> $a['similarity']);
        $top = array_slice($scored, 0, 5);

        return response()->json(['ok' => true, 'duplicates' => $top]);
    }

    /**
     * Trigger an AI re-clustering pass to refresh the duplicate_group_id on
     * every active bug. Gated to roles that can already assign work — the
     * action is idempotent but the AI call costs money, so we don't want
     * every viewer firing it. Optional `project_id` narrows the pass.
     */
    public function detectDuplicates(Request $request, BugDuplicateService $service): JsonResponse
    {
        $user = $request->user();
        if (! ProjectRoleService::hasFeature($user->role, 'agile')) {
            return response()->json(['error' => 'Forbidden'], 403);
        }
        if (! \App\Models\Role::roleHasPermission($user->role, 'agile.assign_items')) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'project_id' => 'nullable|integer|exists:projects,id',
        ]);

        $projectId = $validated['project_id'] ?? null;
        $projectIds = $projectId !== null
            ? [(int) $projectId]
            : \App\Models\Project::orderBy('id')->pluck('id')->all();

        $totals = ['processed' => 0, 'groups' => 0, 'duplicates' => 0];
        foreach ($projectIds as $pid) {
            $res = $service->detectAndStore($pid);
            if (! empty($res['skipped'])) {
                return response()->json(['error' => 'AI service unavailable: '.$res['skipped']], 503);
            }
            $totals['processed'] += $res['processed'];
            $totals['groups'] += $res['groups'];
            $totals['duplicates'] += $res['duplicates'];
        }

        ActivityLogService::log($user->id, 'bugs_duplicates_detected', "{$user->name} ran AI duplicate detection ({$totals['groups']} groups across {$totals['processed']} bugs)", 'bug', null, $totals);

        return response()->json(['ok' => true] + $totals);
    }

    public function destroy(Request $request, Bug $bug): JsonResponse
    {
        $user = $request->user();
        if (! ProjectRoleService::hasFeature($user->role, 'agile')) {
            return response()->json(['error' => 'Forbidden'], 403);
        }
        if (! ProjectRoleService::canCrudAgileBugs($user->role)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $title = $bug->title;
        $bug->delete();
        ActivityLogService::log($user->id, 'bug_deleted', "{$user->name} deleted bug: {$title}", 'bug', null);

        return response()->json(['ok' => true]);
    }

    public function move(Request $request, Bug $bug): JsonResponse
    {
        $user = $request->user();
        if (! ProjectRoleService::hasFeature($user->role, 'agile')) {
            return response()->json(['error' => 'Forbidden'], 403);
        }
        if (! AgileService::canUpdateItem($user, $bug)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'status' => 'required|in:'.implode(',', Bug::STATUSES),
            'sort_order' => 'sometimes|integer|min:0',
        ]);

        $oldStatus = $bug->status;

        if (in_array($validated['status'], [Bug::STATUS_CLOSED, Bug::STATUS_WONT_FIX], true) && ! $bug->resolved_at) {
            $validated['resolved_at'] = now();
        }

        $bug->update($validated);

        if ($validated['status'] !== $oldStatus) {
            ActivityLogService::log($user->id, 'bug_status_changed', "{$user->name} moved bug \"{$bug->title}\" from {$oldStatus} to {$validated['status']}", 'bug', $bug->id, ['from' => $oldStatus, 'to' => $validated['status']]);
            $this->notifyQaIfFixed($bug, $oldStatus, $validated['status'], $user);
        }

        return response()->json(['ok' => true, 'bug' => $this->normalizeBug($bug->fresh()->load(['assignee:id,name', 'reporter:id,name', 'epic:id,title', 'story:id,title', 'sprint:id,name', 'labels', 'attachments']))]);
    }

    /**
     * Save any files in `attachments[]` from the request as bug_attachments rows.
     */
    private function saveAttachments(Request $request, Bug $bug, $user): void
    {
        if (! $request->hasFile('attachments')) {
            return;
        }
        $files = $request->file('attachments');
        $files = is_array($files) ? $files : [$files];
        foreach ($files as $file) {
            if (! $file) {
                continue;
            }
            $path = $file->store('bug-attachments', 'public');
            // store() returns false on disk-write failure (e.g. wrong dir perms).
            // Bail loudly so the API surfaces a 500 instead of writing a row with
            // path='0' that 404s every time anyone clicks the attachment.
            if (! $path || ! is_string($path)) {
                throw new \RuntimeException(
                    'Failed to write bug attachment to storage. Check that '
                    .'storage/app/public/bug-attachments is writable by www-data.'
                );
            }
            \App\Models\BugAttachment::create([
                'bug_id' => $bug->id,
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime' => $file->getMimeType(),
                'size' => $file->getSize(),
                'uploaded_by' => $user->id,
                'created_at' => now(),
            ]);
        }
    }

    /**
     * Delete attachments listed in `attachment_remove_ids[]` (only those belonging to this bug).
     */
    private function removeAttachments(Request $request, Bug $bug): void
    {
        $ids = $request->input('attachment_remove_ids', []);
        if (! is_array($ids) || ! $ids) {
            return;
        }
        $attachments = \App\Models\BugAttachment::where('bug_id', $bug->id)->whereIn('id', $ids)->get();
        foreach ($attachments as $att) {
            if ($att->path) {
                Storage::disk('public')->delete($att->path);
            }
            $att->delete();
        }
    }

    /**
     * Delete a single attachment via its own endpoint.
     */
    public function destroyAttachment(Request $request, Bug $bug, int $attachmentId): JsonResponse
    {
        $user = $request->user();
        if (! ProjectRoleService::hasFeature($user->role, 'agile')) {
            return response()->json(['error' => 'Forbidden'], 403);
        }
        if (! AgileService::canUpdateItem($user, $bug)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }
        $att = \App\Models\BugAttachment::where('bug_id', $bug->id)->find($attachmentId);
        if (! $att) {
            return response()->json(['error' => 'Attachment not found'], 404);
        }
        if ($att->path) {
            Storage::disk('public')->delete($att->path);
        }
        $att->delete();
        return response()->json(['ok' => true]);
    }

    /**
     * DM the assignee on Slack when a bug is assigned (or re-assigned) to
     * them. Fired from both store() and update() — store() passes
     * $oldAssigneeId = null so any assignee on a new bug triggers a DM.
     *
     * Silent on failure: a Slack outage must not block bug creation/update.
     * Self-assignment is intentionally skipped — the assigner doesn't need
     * to be notified about something they just did themselves.
     */
    private function notifyAssigneeOfAssignment(Bug $bug, $actor, ?int $oldAssigneeId): void
    {
        if (! $bug->assignee_id) {
            return; // unassign or no assignee on create — nothing to notify
        }
        if ((int) $bug->assignee_id === (int) $actor->id) {
            return; // self-assignment — no point DMing yourself
        }
        if ((int) $bug->assignee_id === (int) $oldAssigneeId) {
            return; // assignee didn't actually change (defensive — caller already checks)
        }
        if (! in_array((int) $bug->assignee_id, self::BUG_NOTIFY_USER_IDS, true)) {
            return; // not on the developer allowlist — skip the DM
        }

        try {
            $assignee = $bug->assignee_id ? User::find($bug->assignee_id) : null;
            if (! $assignee || ! $assignee->email) {
                return;
            }

            $slack = app(SlackService::class);
            $slackUserId = $slack->lookupByEmail($assignee->email);
            if (! $slackUserId) {
                Log::info('notifyAssigneeOfAssignment: no Slack id for assignee', [
                    'bug_id' => $bug->id,
                    'assignee_id' => $assignee->id,
                    'email' => $assignee->email,
                ]);
                return;
            }

            $lv = $bug->priority ?: $bug->severity;
            $lvLabel = $lv ? ' · '.ucfirst((string) $lv) : '';
            $message = ":bug: *You've been assigned a bug to fix*\n"
                ."#{$bug->id}: {$bug->title}{$lvLabel}\n"
                ."Assigned by {$actor->name}. Open Tessa → Agile → Backlog to view details and start work.";

            $slack->sendDirectMessage($slackUserId, $message);
        } catch (\Throwable $e) {
            Log::warning('notifyAssigneeOfAssignment failed', [
                'bug_id' => $bug->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Notify QA users via Slack DM when a bug transitions into "fixed" (ready to verify).
     * Silent on failure — never blocks the API response.
     */
    private function notifyQaIfFixed(Bug $bug, string $oldStatus, string $newStatus, $actor): void
    {
        if ($newStatus !== Bug::STATUS_FIXED || $oldStatus === Bug::STATUS_FIXED) {
            return;
        }

        try {
            $slack = app(SlackService::class);
            $qaUsers = User::where('role', 'qa_analyst')->whereNotNull('email')->get();

            $title = $bug->title;
            $reporter = $bug->reporter?->name ?? 'someone';
            $project = $bug->project?->name ?? 'a project';
            $bugId = $bug->id;

            $message = ":bug: *Ready for QA* — bug #{$bugId}: {$title}\n"
                . "Project: {$project} · Reporter: {$reporter} · Marked fixed by {$actor->name}";

            foreach ($qaUsers as $qa) {
                $slackUserId = $slack->lookupByEmail($qa->email);
                if (! $slackUserId) {
                    Log::info('notifyQaIfFixed: QA user has no Slack id', ['user_id' => $qa->id, 'email' => $qa->email]);
                    continue;
                }
                $slack->sendDirectMessage($slackUserId, $message);
            }
        } catch (\Throwable $e) {
            Log::warning('notifyQaIfFixed failed', ['bug_id' => $bug->id, 'error' => $e->getMessage()]);
        }
    }

    private function normalizeBug(Bug $b): array
    {
        return AgileService::normalizeBug($b);
    }
}
