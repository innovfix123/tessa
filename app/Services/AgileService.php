<?php

namespace App\Services;

use App\Models\Bug;
use App\Models\Sprint;
use App\Models\Story;
use App\Models\User;
use App\Helpers\DateHelper;
use Carbon\Carbon;

class AgileService
{
    /**
     * Activate a sprint. Enforces only one active sprint per squad.
     */
    public static function activateSprint(Sprint $sprint): array
    {
        if ($sprint->status !== Sprint::STATUS_PLANNING) {
            return ['ok' => false, 'error' => 'Only sprints in planning status can be activated.'];
        }

        $hasActive = Sprint::where('squad_id', $sprint->squad_id)
            ->where('status', Sprint::STATUS_ACTIVE)
            ->exists();

        if ($hasActive) {
            return ['ok' => false, 'error' => 'This squad already has an active sprint. Close it first.'];
        }

        $sprint->update(['status' => Sprint::STATUS_ACTIVE]);

        return ['ok' => true];
    }

    /**
     * Move sprint to review status.
     */
    public static function reviewSprint(Sprint $sprint): array
    {
        if ($sprint->status !== Sprint::STATUS_ACTIVE) {
            return ['ok' => false, 'error' => 'Only active sprints can move to review.'];
        }

        $sprint->update(['status' => Sprint::STATUS_REVIEW]);

        return ['ok' => true];
    }

    /**
     * Close a sprint and calculate velocity.
     */
    public static function closeSprint(Sprint $sprint): array
    {
        if (! in_array($sprint->status, [Sprint::STATUS_ACTIVE, Sprint::STATUS_REVIEW], true)) {
            return ['ok' => false, 'error' => 'Only active or in-review sprints can be closed.'];
        }

        $velocity = (int) $sprint->stories()
            ->where('status', Story::STATUS_DONE)
            ->sum('story_points');

        $sprint->update([
            'status' => Sprint::STATUS_CLOSED,
            'velocity' => $velocity,
        ]);

        return ['ok' => true, 'velocity' => $velocity];
    }

    /**
     * Get board data for a sprint: stories + bugs grouped by status column.
     */
    public static function getBoardData(Sprint $sprint, ?int $filterUserId = null): array
    {
        $storyQuery = $sprint->stories()
            ->with(['assignee:id,name', 'labels', 'epic:id,title'])
            ->orderBy('sort_order');
        if ($filterUserId !== null) {
            $storyQuery->where('assignee_id', $filterUserId);
        }
        $stories = $storyQuery->get();

        $bugQuery = $sprint->bugs()
            // 'attachments' must be eager-loaded so normalizeBug() can build the
            // unified attachments[] array — without it the sprint-board bug modal
            // hides the attachment section, even though the bug still has files.
            ->with(['assignee:id,name', 'labels', 'attachments'])
            ->orderBy('sort_order');
        if ($filterUserId !== null) {
            $bugQuery->where('assignee_id', $filterUserId);
        }
        $bugs = $bugQuery->get();

        $columns = [];
        foreach (Story::BOARD_COLUMNS as $status) {
            $columns[$status] = [
                'stories' => $stories->where('status', $status)->values()->map(fn ($s) => array_merge($s->toArray(), [
                    'storyPoints' => $s->story_points,
                    'acceptanceCriteria' => $s->acceptance_criteria,
                    'assigneeId' => $s->assignee_id,
                    'assigneeName' => $s->assignee?->name,
                    'sprintId' => $s->sprint_id,
                    'projectId' => $s->project_id,
                    '_type' => 'story',
                ])),
                'bugs' => $bugs->filter(fn ($b) => self::mapBugStatusToColumn($b->status) === $status)->values()->map(fn ($b) => array_merge(self::normalizeBug($b), [
                    '_type' => 'bug',
                ])),
            ];
        }

        return $columns;
    }

    /**
     * Shared bug serializer used by both BugController::index (backlog/list) and
     * getBoardData (sprint board). Keeps the response shape identical so the
     * agile-UI bug detail modal renders attachments + screenshot the same way
     * in both places.
     */
    public static function normalizeBug(Bug $b): array
    {
        $screenshotUrl = $b->screenshot_path ? asset('storage/'.$b->screenshot_path) : null;
        $screenshotName = $b->screenshot_path ? basename($b->screenshot_path) : null;
        $isImageAttachment = $screenshotName && (bool) preg_match('/\.(png|jpe?g|gif|webp|svg|bmp)$/i', $screenshotName);

        $attachments = [];
        if ($b->relationLoaded('attachments')) {
            foreach ($b->attachments as $att) {
                $name = $att->original_name ?: basename($att->path);
                $needsReupload = $att->path === '' || $att->path === '0' || $att->path === null;
                $url = $needsReupload ? null : asset('storage/'.$att->path);
                $attachments[] = [
                    'id' => $att->id,
                    'name' => $name,
                    'url' => $url,
                    'mime' => $att->mime,
                    'size' => $att->size,
                    'isImage' => $att->mime ? str_starts_with((string) $att->mime, 'image/') : (bool) preg_match('/\.(png|jpe?g|gif|webp|svg|bmp)$/i', $name),
                    'uploadedAt' => $att->created_at?->toIso8601String(),
                    'needsReupload' => $needsReupload,
                    'legacy' => false,
                ];
            }
        }
        if ($screenshotUrl) {
            array_unshift($attachments, [
                'id' => null,
                'name' => $screenshotName,
                'url' => $screenshotUrl,
                'mime' => null,
                'size' => null,
                'isImage' => $isImageAttachment,
                'uploadedAt' => $b->created_at?->toIso8601String(),
                'legacy' => true,
            ]);
        }

        return [
            'id' => $b->id,
            'title' => $b->title,
            'description' => $b->description ?? '',
            'stepsToReproduce' => $b->steps_to_reproduce ?? '',
            'projectId' => $b->project_id,
            'epicId' => $b->epic_id,
            'epicTitle' => $b->epic?->title ?? '',
            'storyId' => $b->story_id,
            'storyTitle' => $b->story?->title ?? '',
            'sprintId' => $b->sprint_id,
            'sprintName' => $b->sprint?->name ?? '',
            'assigneeId' => $b->assignee_id,
            'assigneeName' => $b->assignee?->name ?? '',
            'reporterId' => $b->reporter_id,
            'reporterName' => $b->reporter?->name ?? '',
            'status' => $b->status,
            'severity' => $b->severity,
            'priority' => $b->priority,
            'storyPoints' => $b->story_points,
            'environment' => $b->environment,
            'screenshotUrl' => $screenshotUrl,
            'attachmentUrl' => $screenshotUrl,
            'attachmentName' => $screenshotName,
            'attachmentIsImage' => $isImageAttachment,
            'attachments' => $attachments,
            'awaitingQa' => $b->status === Bug::STATUS_FIXED,
            'sortOrder' => $b->sort_order,
            'labels' => $b->labels->map(fn ($l) => ['id' => $l->id, 'name' => $l->name, 'color' => $l->color])->values(),
            'resolvedAt' => $b->resolved_at?->toIso8601String(),
            'createdBy' => $b->created_by,
            'createdAt' => $b->created_at?->toIso8601String(),
            'updatedAt' => $b->updated_at?->toIso8601String(),
            // Set by BugDuplicateService when AI clustering flags this bug
            // as a duplicate of others. The frontend resolves the sibling list
            // by grouping state.bugs on duplicateGroupId — keeps this payload
            // O(1) per row and avoids an N+1 sibling lookup here.
            'duplicateGroupId' => $b->duplicate_group_id,
        ];
    }

    /**
     * Map bug statuses to board columns.
     */
    private static function mapBugStatusToColumn(string $bugStatus): string
    {
        return match ($bugStatus) {
            Bug::STATUS_OPEN => Story::STATUS_TODO,
            Bug::STATUS_IN_PROGRESS => Story::STATUS_IN_PROGRESS,
            Bug::STATUS_FIXED => Story::STATUS_CODE_REVIEW,
            Bug::STATUS_VERIFIED => Story::STATUS_QA,
            Bug::STATUS_CLOSED => Story::STATUS_DONE,
            Bug::STATUS_WONT_FIX => Story::STATUS_DONE,
            default => Story::STATUS_TODO,
        };
    }

    /**
     * Get burndown data for a sprint.
     */
    public static function getBurndownData(Sprint $sprint): array
    {
        $totalPoints = $sprint->total_points;
        $startDate = $sprint->start_date->copy();
        $endDate = $sprint->end_date->copy();
        $today = DateHelper::today();

        $days = [];
        $current = $startDate->copy();
        while ($current->lte($endDate)) {
            $dateStr = $current->format('Y-m-d');

            // Count points of stories done on or before this date
            $completedPoints = $sprint->stories()
                ->where('status', Story::STATUS_DONE)
                ->where('updated_at', '<=', $current->copy()->endOfDay())
                ->sum('story_points');

            $remaining = $totalPoints - (int) $completedPoints;

            $days[] = [
                'date' => $dateStr,
                'remaining' => max(0, $remaining),
                'ideal' => null, // filled below
            ];

            $current->addDay();
        }

        // Calculate ideal burndown line
        $totalDays = count($days);
        if ($totalDays > 1) {
            $pointsPerDay = $totalPoints / ($totalDays - 1);
            foreach ($days as $i => &$day) {
                $day['ideal'] = (int) round($totalPoints - ($pointsPerDay * $i));
            }
        }

        return [
            'totalPoints' => $totalPoints,
            'days' => $days,
        ];
    }

    /**
     * Get velocity data across recent sprints for a squad.
     */
    public static function getVelocityData(int $squadId, int $count = 10): array
    {
        $sprints = Sprint::where('squad_id', $squadId)
            ->where('status', Sprint::STATUS_CLOSED)
            ->orderByDesc('end_date')
            ->limit($count)
            ->get(['id', 'name', 'velocity', 'start_date', 'end_date'])
            ->reverse()
            ->values();

        $velocities = $sprints->pluck('velocity')->filter()->values();
        $average = $velocities->count() > 0 ? (int) round($velocities->avg()) : 0;

        return [
            'sprints' => $sprints->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->name,
                'velocity' => $s->velocity ?? 0,
                'startDate' => $s->start_date->format('Y-m-d'),
                'endDate' => $s->end_date->format('Y-m-d'),
            ]),
            'averageVelocity' => $average,
        ];
    }

    /**
     * Get backlog items (stories/bugs not assigned to any sprint) for a squad.
     */
    public static function getBacklog(int $squadId): array
    {
        $epicIds = \App\Models\Epic::where('squad_id', $squadId)->pluck('id');

        $stories = Story::whereNull('sprint_id')
            ->where(function ($q) use ($epicIds) {
                $q->whereIn('epic_id', $epicIds)->orWhereNull('epic_id');
            })
            ->with(['assignee:id,name', 'labels', 'epic:id,title'])
            ->orderByDesc('priority')
            ->orderBy('sort_order')
            ->get();

        $bugs = Bug::whereNull('sprint_id')
            ->where(function ($q) use ($epicIds) {
                $q->whereIn('epic_id', $epicIds)->orWhereNull('epic_id');
            })
            ->with(['assignee:id,name', 'labels'])
            ->orderByDesc('severity')
            ->orderBy('sort_order')
            ->get();

        return [
            'stories' => $stories,
            'bugs' => $bugs,
        ];
    }

    /**
     * Returns the project IDs a user is allowed to see in agile, or null for unrestricted.
     */
    public static function allowedProjectIds(User $user): ?array
    {
        if ($user->role === 'ceo') {
            return null;
        }

        $map = [
            23 => [1],       // Laxmi → Hima
            35 => [1],       // Rishabh → Hima
            36 => [1],       // Raksha → Hima
            53 => [5],       // Iksha → Only Care
            38 => [5],       // Maari → Only Care
            39 => [6],       // Barkha → Astro Website
            37 => [4, 3, 1], // Perumal → Bangalore Connect, Thedal, Hima
            41 => [7],       // Fida → Tessa
            42 => [8],       // Sneha Prathap → Unman
            12 => [2],       // Tamil Arasan → Sudar
            27 => [1, 7, 8, 4, 5], // Ranjini → Hima, Tessa, Unman, Bangalore Connect, Only Care
        ];

        if (isset($map[$user->id])) {
            return $map[$user->id];
        }

        return null;
    }

    public static function scopeToAllowedProjects($query, User $user, string $column = 'project_id')
    {
        $ids = self::allowedProjectIds($user);
        if ($ids !== null) {
            $query->whereIn($column, $ids);
        }
        return $query;
    }

    /**
     * Check if user can manage agile (sprints, epics, squads).
     */
    public static function canManage(string $role): bool
    {
        return \App\Models\Role::roleHasPermission($role, 'agile.manage_sprints');
    }

    /**
     * Check if user can update an item (owner or manager).
     */
    public static function canUpdateItem(User $user, $item): bool
    {
        if (self::canManage($user->role)) {
            return true;
        }

        if (\App\Models\Role::roleHasPermission($user->role, 'agile.update_own_items')) {
            return $item->assignee_id === $user->id || $item->created_by === $user->id;
        }

        return false;
    }

    /**
     * Detect whether adding the given dependency IDs to a story would create a cycle.
     * Walks the dependency graph forward from each candidate; if it reaches the source story, that's a cycle.
     */
    public static function wouldCreateDependencyCycle(Story $story, array $newDepIds): bool
    {
        $newDepIds = array_values(array_unique(array_filter(array_map('intval', $newDepIds))));
        if (in_array($story->id, $newDepIds, true)) {
            return true;
        }

        foreach ($newDepIds as $startId) {
            $visited = [];
            $stack = [$startId];
            while ($stack) {
                $cur = array_pop($stack);
                if ($cur === $story->id) {
                    return true;
                }
                if (isset($visited[$cur])) {
                    continue;
                }
                $visited[$cur] = true;
                $children = \DB::table('story_dependencies')
                    ->where('story_id', $cur)
                    ->pluck('depends_on_story_id')
                    ->all();
                foreach ($children as $c) {
                    $stack[] = (int) $c;
                }
            }
        }
        return false;
    }

    /**
     * Sync a story's dependency list. Rejects cycles.
     */
    public static function syncStoryDependencies(Story $story, array $depIds): array
    {
        $depIds = array_values(array_unique(array_filter(array_map('intval', $depIds))));
        $depIds = array_values(array_filter($depIds, fn ($id) => $id !== $story->id));

        if (self::wouldCreateDependencyCycle($story, $depIds)) {
            return ['ok' => false, 'error' => 'Adding these dependencies would create a circular blockage.'];
        }

        $story->dependencies()->sync($depIds);
        return ['ok' => true];
    }

    /**
     * Capacity status for a sprint: hours assigned vs squad capacity.
     */
    public static function getSprintCapacityStatus(Sprint $sprint): array
    {
        $capacity = (int) ($sprint->capacity_hours ?? 0);
        $assignedHours = (float) \DB::table('agile_tasks')
            ->whereIn('story_id', $sprint->stories()->pluck('id'))
            ->sum('estimated_hours');
        $assignedPoints = (int) $sprint->stories()->sum('story_points');
        $storyCount = (int) $sprint->stories()->count();
        $bugCount = (int) $sprint->bugs()->count();
        $percentUsed = $capacity > 0 ? round(($assignedHours / $capacity) * 100) : null;

        return [
            'capacityHours' => $capacity ?: null,
            'assignedHours' => round($assignedHours, 2),
            'assignedPoints' => $assignedPoints,
            'storyCount' => $storyCount,
            'bugCount' => $bugCount,
            'percentUsed' => $percentUsed,
            'overCapacity' => $capacity > 0 && $assignedHours > $capacity,
        ];
    }

    /**
     * WIP breaches per assignee for a sprint, against squad.wip_limit_per_user.
     * Returns ['limit'=>N|null, 'breaches'=>[['userId'=>id,'userName'=>name,'status'=>col,'count'=>n], ...]]
     */
    public static function getWipBreaches(Sprint $sprint): array
    {
        $squad = $sprint->squad;
        $limit = $squad?->wip_limit_per_user;
        if (! $limit || $limit <= 0) {
            return ['limit' => null, 'breaches' => []];
        }

        $rows = \DB::table('stories')
            ->select('assignee_id', 'status', \DB::raw('COUNT(*) as n'))
            ->where('sprint_id', $sprint->id)
            ->whereNotNull('assignee_id')
            ->whereIn('status', [Story::STATUS_TODO, Story::STATUS_IN_PROGRESS, Story::STATUS_CODE_REVIEW, Story::STATUS_QA])
            ->groupBy('assignee_id', 'status')
            ->havingRaw('n > ?', [$limit])
            ->get();

        if ($rows->isEmpty()) {
            return ['limit' => $limit, 'breaches' => []];
        }

        $userIds = $rows->pluck('assignee_id')->unique()->all();
        $names = User::whereIn('id', $userIds)->pluck('name', 'id');

        $breaches = $rows->map(fn ($r) => [
            'userId' => (int) $r->assignee_id,
            'userName' => $names[$r->assignee_id] ?? 'Unknown',
            'status' => $r->status,
            'count' => (int) $r->n,
        ])->values()->all();

        return ['limit' => $limit, 'breaches' => $breaches];
    }
}
