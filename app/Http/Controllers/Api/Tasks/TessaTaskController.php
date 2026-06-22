<?php

namespace App\Http\Controllers\Api\Tasks;

use App\Helpers\DateHelper;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Traits\TaskAuthorization;
use App\Models\TaskAttachment;
use App\Models\TaskCheckin;
use App\Models\TaskMessage;
use App\Models\TaskParticipant;
use App\Models\TessaTask;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\TessaTaskService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TessaTaskController extends Controller
{
    use TaskAuthorization;

    // Leadership accounts skipped when a task is assigned to "Everyone".
    // JP, Bala, Nandha, Ayush — they don't take on the company-wide chore.
    private const EVERYONE_EXCLUDED_USER_IDS = [1, 2, 3, 4];

    private const ATTACHMENT_ALLOWED_MIMES = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp',
        'application/pdf',
        'text/plain', 'text/csv',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/zip',
    ];

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $filter = $request->query('filter', 'all'); // all, assigned_to_me, assigned_by_me, awaiting_my_verification
        $status = $request->query('status'); // pending, in_progress, on_track, at_risk, off_track, completed, closed, cancelled, on_hold
        $search = $request->query('search');
        $priority = $request->query('priority');
        $deadlineFrom = $request->query('deadline_from');
        $deadlineTo = $request->query('deadline_to');
        $includeClosed = $request->query('include_closed', '1') !== '0';
        $assigneeId = $request->query('assignee_id');
        $assignedById = $request->query('assigned_by_id');

        $query = TessaTask::with(['assignedBy:id,name', 'assignedTo:id,name', 'sharedAssignedBy:id,name']);

        if ($filter === 'assigned_to_me') {
            $query->where('assigned_to', $user->id);
        } elseif ($filter === 'assigned_by_me') {
            $query->where('assigned_by', $user->id);
        } elseif ($filter === 'awaiting_my_verification') {
            $query->where('assigned_by', $user->id)->where('status', 'completed');
        } else {
            $query->where(fn ($q) => $q->where('assigned_to', $user->id)->orWhere('assigned_by', $user->id));
        }

        if ($status) {
            $query->where('status', $status);
        }

        if (! $includeClosed) {
            $query->whereNotIn('status', ['closed', 'cancelled']);
        }

        if ($assigneeId) {
            $query->where('assigned_to', (int) $assigneeId);
        }

        if ($assignedById) {
            $query->where('assigned_by', (int) $assignedById);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if ($priority) {
            $query->where('priority', $priority);
        }

        if ($deadlineFrom) {
            $query->where('deadline', '>=', $deadlineFrom);
        }

        if ($deadlineTo) {
            $query->where('deadline', '<=', $deadlineTo . ' 23:59:59');
        }

        $tasks = $query->withCount('messages')
            ->withCount(['subtasks', 'subtasks as subtasks_completed_count' => function ($q) {
                $q->where('is_completed', true);
            }])
            ->addSelect(['unread_count' => TaskMessage::selectRaw('count(*)')
                ->whereColumn('task_messages.task_id', 'tessa_tasks.id')
                ->where('task_messages.user_id', '!=', $user->id)
                ->whereRaw('task_messages.created_at > COALESCE(
                    (SELECT last_read_at FROM task_participants
                     WHERE task_participants.task_id = tessa_tasks.id
                     AND task_participants.user_id = ?), ?)', [$user->id, '1970-01-01'])
            ])
            ->with('participants.user:id,name')
            ->orderByRaw("FIELD(status, 'pending', 'in_progress', 'on_hold', 'completed', 'closed', 'cancelled')")
            ->orderByRaw("FIELD(priority, 'urgent', 'high', 'medium', 'low')")
            ->orderBy('deadline')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'tasks' => $tasks->map(fn (TessaTask $t) => [
                'id' => $t->id,
                'title' => $t->title,
                'description' => $t->description,
                'priority' => $t->priority,
                'status' => $t->status,
                'status_note' => $t->status_note,
                'ai_summary' => $t->ai_summary,
                'blocker_status' => $t->blocker_status,
                'blocker_note' => $t->blocker_note,
                'progress' => $t->progress ?? 0,
                'message_count' => $t->messages_count ?? 0,
                'subtask_total' => $t->subtasks_count ?? 0,
                'subtask_done' => $t->subtasks_completed_count ?? 0,
                'unread_count' => (int) ($t->unread_count ?? 0),
                'people' => $t->participants->map(fn ($p) => [
                    'name' => $p->user?->name ?? '?',
                    'role' => $p->role,
                ])->values(),
                'assigned_by' => $t->assignedBy ? ['id' => $t->assignedBy->id, 'name' => $t->assignedBy->name] : null,
                'assigned_to' => $t->assignedTo ? ['id' => $t->assignedTo->id, 'name' => $t->assignedTo->name] : null,
                'deadline' => $t->deadline?->toIso8601String(),
                'original_deadline' => $t->original_deadline?->toIso8601String(),
                'deadline_extension_count' => $t->deadline_extension_count,
                'pending_extension_days' => $t->pending_extension_days,
                'extension_notice_days' => $t->extension_notice_days,
                'is_overdue' => $t->isOverdue(),
                'is_mandatory' => (bool) $t->is_mandatory,
                'requires_attachment' => (bool) $t->requires_attachment,
                'requires_form_url' => $t->requires_form_url,
                'proof_submitted_at' => $t->proof_submitted_at?->toIso8601String(),
                'proof_note' => $t->proof_note,
                'remind_count' => $t->remind_count,
                'completed_at' => $t->completed_at?->toIso8601String(),
                'closed_at' => $t->closed_at?->toIso8601String(),
                'closed_by' => $t->closed_by,
                'reopen_count' => $t->reopen_count ?? 0,
                'reopen_reason' => $t->reopen_reason,
                'created_at' => $t->created_at->toIso8601String(),
                'updated_at' => $t->updated_at->toIso8601String(),
            ]),
        ]);
    }

    public function myActionNeeded(Request $request): JsonResponse
    {
        $user = $request->user();
        $today = Carbon::today('Asia/Kolkata');

        $tasks = TessaTask::where('assigned_to', $user->id)
            ->whereIn('status', TessaTask::ACTIVE_STATUSES)
            ->whereNotNull('deadline')
            ->orderBy('deadline')
            ->get();

        if ($tasks->isEmpty()) {
            return response()->json(['items' => []]);
        }

        $taskIds = $tasks->pluck('id')->toArray();

        // Get all checkins per task for this user (dates + last note)
        $allCheckins = TaskCheckin::whereIn('task_id', $taskIds)
            ->where('user_id', $user->id)
            ->orderBy('created_at')
            ->get(['task_id', 'created_at', 'note']);

        $checkinDates = $allCheckins->groupBy('task_id')
            ->map(fn ($checkins) => $checkins->map(fn ($c) => $c->created_at->format('Y-m-d'))->toArray());

        $lastNotes = $allCheckins->groupBy('task_id')
            ->map(fn ($checkins) => $checkins->last()?->note);

        $result = [];
        foreach ($tasks as $task) {
            $taskCheckinDates = $checkinDates->get($task->id, []);

            // Determine start date: day after task creation
            $taskCreatedDate = $task->created_at->copy()->timezone('Asia/Kolkata')->startOfDay();
            $startDate = $taskCreatedDate->copy()->addDay();

            // Don't go back more than 7 days
            $minDate = $today->copy()->subDays(7);
            if ($startDate->lt($minDate)) {
                $startDate = $minDate;
            }

            // Find each missing day from startDate to today (weekdays only)
            $date = $startDate->copy();
            while ($date->lte($today)) {
                // Skip weekends
                if ($date->isWeekday()) {
                    $dateStr = $date->format('Y-m-d');
                    if (! in_array($dateStr, $taskCheckinDates)) {
                        $isToday = $date->equalTo($today);
                        $result[] = [
                            'id' => $task->id,
                            'title' => $task->title,
                            'priority' => $task->priority,
                            'status' => $task->status,
                            'progress' => $task->progress ?? 0,
                            'blocker_status' => $task->blocker_status,
                            'checkin_date' => $dateStr,
                            'is_today' => $isToday,
                            'date_label' => $isToday ? 'Today' : $date->format('D, d M'),
                            'last_note' => $lastNotes->get($task->id),
                        ];
                    }
                }
                $date->addDay();
            }
        }

        // Sort: oldest missed dates first
        usort($result, function ($a, $b) {
            return strcmp($a['checkin_date'], $b['checkin_date']);
        });

        return response()->json(['items' => $result]);
    }

    public function checkinQuestions(Request $request): JsonResponse
    {
        $user = $request->user();

        // Reuse myActionNeeded logic
        $itemsResponse = $this->myActionNeeded($request);
        $items = json_decode($itemsResponse->getContent(), true)['items'] ?? [];

        if (empty($items)) {
            return response()->json(['questions' => []]);
        }

        $ai = app(\App\Services\TessaAIService::class);
        $questions = $ai->generateCheckinQuestions($items);

        // Map questions to keys: "{taskId}_{checkinDate}"
        $mapped = [];
        foreach ($items as $i => $item) {
            $key = $item['id'] . '_' . $item['checkin_date'];
            $mapped[$key] = $questions[$i] ?? null;
        }

        return response()->json(['questions' => $mapped]);
    }

    public function checkinQuestion(TessaTask $task, Request $request): JsonResponse
    {
        $user = $request->user();
        $this->authorizeTaskAccess($task, $user);

        $dateStr = $request->query('date', DateHelper::today()->format('Y-m-d'));
        $dateLabel = DateHelper::parse($dateStr)->format('D, d M');
        $isToday = $dateStr === DateHelper::today()->format('Y-m-d');

        // Get last checkin note for context
        $lastCheckin = TaskCheckin::where('task_id', $task->id)
            ->orderByDesc('created_at')
            ->first(['note', 'progress', 'health_status']);

        $item = [
            'title' => $task->title,
            'date_label' => $isToday ? 'Today' : $dateLabel,
            'progress' => $lastCheckin->progress ?? $task->progress ?? 0,
            'last_note' => $lastCheckin->note ?? null,
            'blocker_status' => $task->blocker_status,
        ];

        // Check cache
        $cacheKey = 'checkin_q_' . $task->id . '_' . $dateStr;
        $cached = cache()->get($cacheKey);
        if ($cached) {
            return response()->json(['question' => $cached]);
        }

        $ai = app(\App\Services\TessaAIService::class);
        $questions = $ai->generateCheckinQuestions([$item]);
        $question = $questions[0] ?? null;

        if ($question) {
            cache()->put($cacheKey, $question, now()->endOfDay());
        }

        return response()->json(['question' => $question]);
    }

    public function teamActionNeeded(Request $request): JsonResponse
    {
        $manager = $request->user();
        $today = Carbon::today('Asia/Kolkata');

        // Get direct reports
        $teamUserIds = User::where('reporting_manager_id', $manager->id)
            ->where('is_active', true)
            ->pluck('id')
            ->toArray();

        if (empty($teamUserIds)) {
            return response()->json(['tasks' => []]);
        }

        // Active tasks assigned to team members with deadline within next 7 days
        $tasks = TessaTask::with(['assignedTo:id,name'])
            ->whereIn('assigned_to', $teamUserIds)
            ->whereIn('status', TessaTask::ACTIVE_STATUSES)
            ->whereNotNull('deadline')
            ->where('deadline', '<=', $today->copy()->addDays(7)->endOfDay())
            ->orderBy('deadline')
            ->get();

        // Check which tasks have no checkin today
        $taskIds = $tasks->pluck('id')->toArray();
        $checkedInTodayTaskIds = TaskCheckin::whereIn('task_id', $taskIds)
            ->whereDate('created_at', $today->format('Y-m-d'))
            ->pluck('task_id')
            ->unique()
            ->toArray();

        $result = [];
        foreach ($tasks as $task) {
            $hasCheckinToday = in_array($task->id, $checkedInTodayTaskIds);
            if ($hasCheckinToday) {
                continue; // skip tasks that already have today's EOD update
            }

            $daysSince = $task->last_checkin_at
                ? (int) $task->last_checkin_at->copy()->timezone('Asia/Kolkata')->startOfDay()->diffInDays($today)
                : null;

            $result[] = [
                'id' => $task->id,
                'title' => $task->title,
                'assigned_to' => $task->assignedTo ? ['id' => $task->assignedTo->id, 'name' => $task->assignedTo->name] : null,
                'priority' => $task->priority,
                'status' => $task->status,
                'progress' => $task->progress ?? 0,
                'blocker_status' => $task->blocker_status,
                'deadline' => $task->deadline->toIso8601String(),
                'is_overdue' => $task->isOverdue(),
                'last_checkin_at' => $task->last_checkin_at?->toIso8601String(),
                'days_since_update' => $daysSince,
            ];
        }

        return response()->json(['tasks' => $result]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $request->validate([
            'assigned_to' => 'nullable|exists:users,id',
            'assign_to_all' => 'nullable|boolean',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'priority' => 'in:low,medium,high,urgent',
            'deadline' => 'nullable|date',
            'attachments' => 'nullable|array|max:20',
            'attachments.*' => 'file|max:10240',
            'is_mandatory' => 'nullable|boolean',
            'requires_attachment' => 'nullable|boolean',
            'requires_form_url' => 'nullable|url|max:1000',
        ]);

        $assignToAll = (bool) $request->input('assign_to_all', false);
        if (! $assignToAll && ! $request->filled('assigned_to')) {
            return response()->json(['error' => 'Pick an assignee or enable Everyone.'], 422);
        }

        // Everyone-mode tasks default to mandatory (company-wide form/upload chores).
        // Reporter can flip it off explicitly via the toggle.
        $isMandatory = $assignToAll
            ? (bool) $request->input('is_mandatory', true)
            : (bool) $request->input('is_mandatory', false);
        $requiresAttachment = (bool) $request->input('requires_attachment', false);
        $requiresFormUrl = trim((string) $request->input('requires_form_url', '')) ?: null;

        // The two requirement gates only make sense on mandatory tasks. If reporter
        // turned mandatory off, clear the requirement fields so they don't confuse
        // the assignee or KRA scoring later.
        if (! $isMandatory) {
            $requiresAttachment = false;
            $requiresFormUrl = null;
        }

        $files = $request->file('attachments', []);
        foreach ($files as $file) {
            if (! in_array($file->getMimeType(), self::ATTACHMENT_ALLOWED_MIMES)) {
                return response()->json(['error' => 'File type not allowed: ' . $file->getClientOriginalName()], 422);
            }
        }

        $service = app(TessaTaskService::class);

        if ($assignToAll) {
            if (! empty($files)) {
                return response()->json(['error' => 'Attachments are not supported when assigning to Everyone. Put links in the description.'], 422);
            }

            $excludedIds = array_unique(array_merge(self::EVERYONE_EXCLUDED_USER_IDS, [$user->id]));
            $userIds = User::where('is_active', true)
                ->whereNotIn('id', $excludedIds)
                ->pluck('id')
                ->toArray();

            if (empty($userIds)) {
                return response()->json(['error' => 'No active employees to assign to.'], 422);
            }

            $created = 0;
            foreach ($userIds as $uid) {
                $task = $service->createAndNotify(
                    $user,
                    (int) $uid,
                    $request->input('title'),
                    $request->input('description'),
                    $request->input('priority', 'medium'),
                    $request->input('deadline'),
                    $isMandatory,
                    $requiresAttachment,
                    $requiresFormUrl
                );
                TaskParticipant::firstOrCreate(
                    ['task_id' => $task->id, 'user_id' => $user->id],
                    ['role' => 'assigner']
                );
                if ($task->assigned_to !== $user->id) {
                    TaskParticipant::firstOrCreate(
                        ['task_id' => $task->id, 'user_id' => $task->assigned_to],
                        ['role' => 'assignee']
                    );
                }
                $created++;
            }

            ActivityLogService::log(
                $user->id,
                'task_assigned_everyone',
                "{$user->name} assigned task \"{$request->input('title')}\" to {$created} employees",
            );

            return response()->json(['ok' => true, 'count' => $created], 201);
        }

        $task = $service->createAndNotify(
            $user,
            (int) $request->input('assigned_to'),
            $request->input('title'),
            $request->input('description'),
            $request->input('priority', 'medium'),
            $request->input('deadline'),
            $isMandatory,
            $requiresAttachment,
            $requiresFormUrl
        );

        foreach ($files as $file) {
            $path = $file->store('task-attachments/' . $task->id, 'local');
            TaskAttachment::create([
                'task_id' => $task->id,
                'message_id' => null,
                'user_id' => $user->id,
                'file_name' => $file->getClientOriginalName(),
                'file_path' => $path,
                'file_size' => $file->getSize(),
                'mime_type' => $file->getMimeType(),
                'created_at' => now(),
            ]);
        }

        // Auto-create thread participants
        TaskParticipant::firstOrCreate(
            ['task_id' => $task->id, 'user_id' => $user->id],
            ['role' => 'assigner']
        );
        if ($task->assigned_to !== $user->id) {
            TaskParticipant::firstOrCreate(
                ['task_id' => $task->id, 'user_id' => $task->assigned_to],
                ['role' => 'assignee']
            );
        }

        ActivityLogService::log(
            $user->id,
            'task_assigned',
            "{$user->name} assigned task \"{$task->title}\" to user #{$task->assigned_to}",
            'tessa_task',
            $task->id,
            ['target_user_id' => $task->assigned_to],
        );

        return response()->json(['ok' => true, 'task' => $task->load(['assignedBy:id,name', 'assignedTo:id,name', 'sharedAssignedBy:id,name'])], 201);
    }

    public function show(TessaTask $task, Request $request): JsonResponse
    {
        $this->authorizeTaskOwner($task, $request->user());

        $task->load([
            'assignedBy:id,name',
            'assignedTo:id,name',
            'sharedAssignedBy:id,name',
            'dependencies:id,title,status',
            'dependents:id,title,status',
        ]);

        $payload = $task->toArray();
        $payload['dependencies'] = $task->dependencies->map(fn ($d) => [
            'id' => $d->id,
            'title' => $d->title,
            'status' => $d->status,
        ])->values();
        $payload['dependents'] = $task->dependents->map(fn ($d) => [
            'id' => $d->id,
            'title' => $d->title,
            'status' => $d->status,
        ])->values();

        $linkedIds = DB::table('task_links')
            ->where('task_a_id', $task->id)->pluck('task_b_id')
            ->merge(DB::table('task_links')->where('task_b_id', $task->id)->pluck('task_a_id'))
            ->unique()
            ->all();
        $payload['linked'] = TessaTask::whereIn('id', $linkedIds)
            ->get(['id', 'title', 'status'])
            ->map(fn ($d) => ['id' => $d->id, 'title' => $d->title, 'status' => $d->status])
            ->values();

        $payload['blockers'] = $task->blockers()->with('creator:id,name')->get()->map(fn ($b) => [
            'id' => $b->id,
            'note' => $b->note,
            'created_at' => $b->created_at?->toIso8601String(),
            'created_by' => $b->creator ? ['id' => $b->creator->id, 'name' => $b->creator->name] : null,
        ])->values();

        return response()->json(['task' => $payload]);
    }

    public function myAssigneesOptions(Request $request): JsonResponse
    {
        $user = $request->user();
        $rows = TessaTask::where('assigned_by', $user->id)
            ->whereNotNull('assigned_to')
            ->where('assigned_to', '!=', $user->id)
            ->with('assignedTo:id,name')
            ->get(['id', 'assigned_to'])
            ->pluck('assignedTo')
            ->filter()
            ->unique('id')
            ->sortBy('name')
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])
            ->values();

        return response()->json(['options' => $rows]);
    }

    public function dependenciesOptions(Request $request): JsonResponse
    {
        $user = $request->user();
        $search = trim((string) $request->query('search', ''));
        $excludeId = (int) $request->query('exclude', 0);
        $excludeIds = (array) $request->query('exclude_ids', []);
        $excludeIds = array_values(array_unique(array_filter(array_map('intval', $excludeIds))));

        $query = TessaTask::query()
            ->where(fn ($q) => $q->where('assigned_to', $user->id)->orWhere('assigned_by', $user->id))
            ->whereNotIn('status', ['closed', 'cancelled']);

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
            // Legacy single-section picker: also exclude tasks already in `dependencies`.
            // Skip this fallback when caller provides explicit exclude_ids (modal flow).
            if (empty($excludeIds)) {
                $existingDepIds = DB::table('task_dependencies')
                    ->where('task_id', $excludeId)
                    ->pluck('depends_on_task_id')
                    ->all();
                if (! empty($existingDepIds)) {
                    $query->whereNotIn('id', $existingDepIds);
                }
            }
        }

        if (! empty($excludeIds)) {
            $query->whereNotIn('id', $excludeIds);
        }

        if ($search !== '') {
            $query->where('title', 'like', "%{$search}%");
        }

        $tasks = $query->orderBy('title')->limit(20)->get(['id', 'title', 'status']);

        return response()->json(['options' => $tasks]);
    }

    public function aiExpand(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:5000',
        ]);

        $title = trim($validated['title']);
        $hint = trim($validated['description'] ?? '');

        $systemPrompt = 'You write task descriptions for a workplace task tracker. Given a title and an optional draft hint, write a clear, concise description in 2-4 short sentences covering goal, scope, and acceptance criteria. Plain text only — no markdown, no headings, no preamble.';
        $userMessage = $hint !== ''
            ? "Title: {$title}\nDraft hint: {$hint}"
            : "Title: {$title}";

        $ai = app(\App\Services\TessaAIService::class);
        $expanded = trim($ai->quickAi($systemPrompt, $userMessage));

        if ($expanded === '') {
            return response()->json(['error' => 'AI service is not available.'], 503);
        }

        return response()->json(['description' => $expanded]);
    }

    public function update(TessaTask $task, Request $request): JsonResponse
    {
        $user = $request->user();
        $this->authorizeTaskOwner($task, $user);

        $request->validate([
            'status' => 'sometimes|in:pending,in_progress,completed,cancelled,on_hold',
            'status_note' => 'nullable|string',
            'blocker_status' => 'sometimes|nullable|in:on_track,at_risk,blocked',
            'priority' => 'sometimes|in:low,medium,high,urgent',
            'title' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'deadline' => 'nullable|date',
            'progress' => 'sometimes|integer|min:0|max:100',
            'assigned_to' => 'sometimes|exists:users,id',
            'dependency_ids' => 'sometimes|array',
            'dependency_ids.*' => 'integer|exists:tessa_tasks,id',
            'blocking_ids' => 'sometimes|array',
            'blocking_ids.*' => 'integer|exists:tessa_tasks,id',
            'link_ids' => 'sometimes|array',
            'link_ids.*' => 'integer|exists:tessa_tasks,id',
            'is_mandatory' => 'sometimes|boolean',
            'requires_attachment' => 'sometimes|boolean',
            'requires_form_url' => 'sometimes|nullable|url|max:1000',
        ]);

        // Mandatory-flag and its requirement gates are reporter-only — the assignee
        // can't loosen the rules on a task assigned to them.
        $mandatoryFields = ['is_mandatory', 'requires_attachment', 'requires_form_url'];
        $touchingMandatory = collect($mandatoryFields)->contains(fn ($f) => $request->has($f));
        if ($touchingMandatory && $task->assigned_by !== $user->id) {
            return response()->json([
                'error' => 'Only the task creator can change mandatory or attachment requirements.',
            ], 403);
        }

        // Closed tasks are immutable; reporter must reopen first.
        if ($task->status === 'closed') {
            return response()->json(['error' => 'Closed tasks cannot be edited. Reopen the task first.'], 422);
        }

        // Once awaiting verification, the reporter alone controls next steps via verify/reopen.
        if ($task->status === 'completed' && $request->has('status')) {
            return response()->json([
                'error' => 'Task is awaiting verification. The reporter must use Verify & Close or Reopen.',
            ], 422);
        }

        // Only the reporter (creator) can change the deadline directly. Assignees use the extend-deadline flow.
        if ($request->has('deadline') && $task->assigned_by !== $user->id) {
            return response()->json([
                'error' => 'Only the task creator can change the due date. Use Extend Deadline to request more time.',
            ], 403);
        }

        // Handle reassignment — only the assigner can reassign
        if ($request->has('assigned_to') && (int) $request->input('assigned_to') !== $task->assigned_to) {
            if ($task->assigned_by !== $user->id) {
                return response()->json(['error' => 'Only the task creator can reassign'], 403);
            }

            $service = app(TessaTaskService::class);
            $task = $service->reassignTask($task, $user, (int) $request->input('assigned_to'));

            ActivityLogService::log(
                $user->id,
                'task_reassigned',
                "{$user->name} reassigned task \"{$task->title}\" to user #{$task->assigned_to}",
                'tessa_task',
                $task->id,
                ['target_user_id' => $task->assigned_to],
            );

            return response()->json(['ok' => true, 'task' => $task->load(['assignedBy:id,name', 'assignedTo:id,name', 'sharedAssignedBy:id,name'])]);
        }

        $updates = $request->only(['status', 'status_note', 'blocker_status', 'priority', 'title', 'description', 'deadline', 'progress']);

        if ($request->has('is_mandatory')) {
            $updates['is_mandatory'] = (bool) $request->input('is_mandatory');
        }
        if ($request->has('requires_attachment')) {
            $updates['requires_attachment'] = (bool) $request->input('requires_attachment');
        }
        if ($request->has('requires_form_url')) {
            $url = trim((string) $request->input('requires_form_url', ''));
            $updates['requires_form_url'] = $url !== '' ? $url : null;
        }

        // If reporter is turning mandatory off, scrub the requirement gates too —
        // leftover "requires_attachment=true" with is_mandatory=false would be
        // ignored by the gate check but is misleading in the UI.
        if (array_key_exists('is_mandatory', $updates) && ! $updates['is_mandatory']) {
            $updates['requires_attachment'] = false;
            $updates['requires_form_url'] = null;
        }

        // Block the completed transition when mandatory requirements aren't met.
        // Cancelled is unaffected — reporter explicitly killing the task isn't
        // a "completion" claim.
        if (isset($updates['status']) && $updates['status'] === 'completed') {
            $gateErrors = $task->completionGateErrors($user->id);
            if (! empty($gateErrors)) {
                return response()->json([
                    'error' => $gateErrors[0],
                    'errors' => $gateErrors,
                    'gate' => 'mandatory_requirements',
                ], 422);
            }
        }

        // When the task transitions to a closed state, clear the health flag —
        // a Completed/Cancelled task doesn't need an "On Track" indicator.
        if (isset($updates['status']) && in_array($updates['status'], ['completed', 'cancelled'], true)) {
            $updates['blocker_status'] = null;
        }

        if (isset($updates['status']) && $updates['status'] === 'completed' && ! $task->completed_at) {
            $updates['completed_at'] = now();
        }

        if (isset($updates['status']) && $updates['status'] === 'cancelled') {
            $updates['completed_at'] = null;
        }

        $task->update($updates);

        if ($request->has('dependency_ids')) {
            $depResult = TessaTaskService::syncTaskDependencies($task, (array) $request->input('dependency_ids', []));
            if (! $depResult['ok']) {
                return response()->json(['error' => $depResult['error']], 422);
            }
        }

        if ($request->has('blocking_ids')) {
            $blkResult = TessaTaskService::syncTaskBlocking($task, (array) $request->input('blocking_ids', []));
            if (! $blkResult['ok']) {
                return response()->json(['error' => $blkResult['error']], 422);
            }
        }

        if ($request->has('link_ids')) {
            TessaTaskService::syncTaskLinks($task, (array) $request->input('link_ids', []));
        }

        ActivityLogService::log(
            $user->id,
            'task_updated',
            "{$user->name} updated task \"{$task->title}\"",
        );

        // Notify assigner on status change (completed, on_hold, cancelled)
        if (isset($updates['status'])) {
            app(TessaTaskService::class)->notifyStatusChange($task, $user, $updates['status']);
        }

        $task = $task->fresh()->load([
            'assignedBy:id,name',
            'assignedTo:id,name',
            'sharedAssignedBy:id,name',
            'dependencies:id,title,status',
            'dependents:id,title,status',
        ]);
        $payload = $task->toArray();
        $payload['dependencies'] = $task->dependencies->map(fn ($d) => ['id' => $d->id, 'title' => $d->title, 'status' => $d->status])->values();
        $payload['dependents'] = $task->dependents->map(fn ($d) => ['id' => $d->id, 'title' => $d->title, 'status' => $d->status])->values();
        $linkedIds = DB::table('task_links')
            ->where('task_a_id', $task->id)->pluck('task_b_id')
            ->merge(DB::table('task_links')->where('task_b_id', $task->id)->pluck('task_a_id'))
            ->unique()->all();
        $payload['linked'] = TessaTask::whereIn('id', $linkedIds)
            ->get(['id', 'title', 'status'])
            ->map(fn ($d) => ['id' => $d->id, 'title' => $d->title, 'status' => $d->status])
            ->values();

        return response()->json(['ok' => true, 'task' => $payload]);
    }

    /**
     * Redirect a task to someone else in the company. Available to the current
     * assignee or the existing shared assigner (the latest delegator) — the
     * original creator uses the reassign path in update() instead. Records the
     * actor as the new shared assigner; the due date carries over unless a new
     * one is supplied.
     */
    public function redirect(TessaTask $task, Request $request): JsonResponse
    {
        $user = $request->user();

        if ($task->assigned_to !== $user->id && $task->shared_assigned_by !== $user->id) {
            return response()->json(['error' => 'Only the current assignee or shared assigner can redirect this task.'], 403);
        }

        if (in_array($task->status, ['completed', 'closed', 'cancelled'], true)) {
            return response()->json(['error' => 'This task can no longer be redirected.'], 422);
        }

        $data = $request->validate([
            'assigned_to' => 'required|exists:users,id',
            'deadline' => 'nullable|date',
        ]);

        $newAssigneeId = (int) $data['assigned_to'];
        if ($newAssigneeId === $task->assigned_to) {
            return response()->json(['error' => 'The task is already assigned to that person.'], 422);
        }

        $task = app(TessaTaskService::class)->redirectTask($task, $user, $newAssigneeId, $data['deadline'] ?? null);

        ActivityLogService::log(
            $user->id,
            'task_redirected',
            "{$user->name} redirected task \"{$task->title}\" to user #{$task->assigned_to}",
            'tessa_task',
            $task->id,
            ['target_user_id' => $task->assigned_to],
        );

        return response()->json([
            'ok' => true,
            'task' => $task->load(['assignedBy:id,name', 'assignedTo:id,name', 'sharedAssignedBy:id,name']),
        ]);
    }

    public function destroy(TessaTask $task, Request $request): JsonResponse
    {
        $user = $request->user();
        if ($task->assigned_by !== $user->id) {
            return response()->json(['error' => 'Only the task creator can delete it'], 403);
        }

        $task->delete();

        return response()->json(['ok' => true]);
    }

    public function escalate(TessaTask $task, Request $request): JsonResponse
    {
        $task->load(['assignedBy', 'assignedTo']);

        if (! $task->deadline || $task->deadline->isFuture()) {
            return response()->json(['error' => 'Task is not overdue'], 422);
        }

        app(TessaTaskService::class)->escalateOverdue($task);

        return response()->json(['ok' => true, 'message' => 'Escalated to reporter via Slack']);
    }

    public function extendDeadline(TessaTask $task, Request $request): JsonResponse
    {
        $user = $request->user();

        if ($task->assigned_to !== $user->id) {
            return response()->json(['error' => 'Only the assignee can extend the deadline'], 403);
        }

        if (in_array($task->status, ['completed', 'cancelled'])) {
            return response()->json(['error' => 'Task is already ' . $task->status], 422);
        }

        if (! $task->deadline) {
            return response()->json(['error' => 'Task has no deadline to extend'], 422);
        }

        if ($task->pending_extension_days) {
            return response()->json(['error' => 'An extension request is already pending approval'], 422);
        }

        $validated = $request->validate([
            'days' => ['required', 'integer', 'in:1,2'],
        ]);

        $days = (int) $validated['days'];
        $service = app(TessaTaskService::class);
        $task->load(['assignedBy', 'assignedTo']);

        if ($task->deadline_extension_count === 0) {
            $task = $service->extendDeadline($task, $user, $days);

            ActivityLogService::log(
                $user->id,
                'task_deadline_extended',
                "{$user->name} extended deadline of \"{$task->title}\" by {$days} day(s)",
            );

            return response()->json([
                'ok' => true,
                'task' => $task->load(['assignedBy:id,name', 'assignedTo:id,name', 'sharedAssignedBy:id,name']),
                'message' => "Deadline extended by {$days} day(s). Reporter notified.",
            ]);
        }

        $service->requestDeadlineExtension($task, $user, $days);

        ActivityLogService::log(
            $user->id,
            'task_extension_requested',
            "{$user->name} requested deadline extension of \"{$task->title}\" by {$days} day(s)",
        );

        return response()->json([
            'ok' => true,
            'pending' => true,
            'task' => $task->fresh()->load(['assignedBy:id,name', 'assignedTo:id,name', 'sharedAssignedBy:id,name']),
            'message' => "Extension request sent to reporter for approval.",
        ]);
    }

    public function approveExtension(TessaTask $task, Request $request): JsonResponse
    {
        $user = $request->user();

        if ($task->assigned_by !== $user->id) {
            return response()->json(['error' => 'Only the reporter can approve extensions'], 403);
        }

        if (! $task->pending_extension_days) {
            return response()->json(['error' => 'No pending extension request'], 422);
        }

        $task->load(['assignedBy', 'assignedTo']);
        $task = app(TessaTaskService::class)->approveExtension($task, $user);

        ActivityLogService::log(
            $user->id,
            'task_extension_approved',
            "{$user->name} approved deadline extension for \"{$task->title}\"",
        );

        return response()->json([
            'ok' => true,
            'task' => $task->load(['assignedBy:id,name', 'assignedTo:id,name', 'sharedAssignedBy:id,name']),
            'message' => 'Extension approved. Assignee notified.',
        ]);
    }

    public function denyExtension(TessaTask $task, Request $request): JsonResponse
    {
        $user = $request->user();

        if ($task->assigned_by !== $user->id) {
            return response()->json(['error' => 'Only the reporter can deny extensions'], 403);
        }

        if (! $task->pending_extension_days) {
            return response()->json(['error' => 'No pending extension request'], 422);
        }

        $task->load(['assignedBy', 'assignedTo']);
        $task = app(TessaTaskService::class)->denyExtension($task, $user);

        ActivityLogService::log(
            $user->id,
            'task_extension_denied',
            "{$user->name} denied deadline extension for \"{$task->title}\"",
        );

        return response()->json([
            'ok' => true,
            'task' => $task->load(['assignedBy:id,name', 'assignedTo:id,name', 'sharedAssignedBy:id,name']),
            'message' => 'Extension denied. Assignee notified.',
        ]);
    }

    public function clearExtensionNotice(TessaTask $task, Request $request): JsonResponse
    {
        $user = $request->user();

        if ($task->assigned_by !== $user->id) {
            return response()->json(['error' => 'Only the reporter can clear this notice'], 403);
        }

        if (! $task->extension_notice_days) {
            return response()->json(['error' => 'No extension notice to clear'], 422);
        }

        app(TessaTaskService::class)->clearExtensionNotice($task);

        return response()->json(['ok' => true]);
    }

    public function extensionInbox(Request $request): JsonResponse
    {
        $user = $request->user();

        $tasks = TessaTask::where('assigned_by', $user->id)
            ->where(function ($q) {
                $q->whereNotNull('extension_notice_days')
                    ->orWhereNotNull('pending_extension_days');
            })
            ->with('assignedTo:id,name')
            ->orderByDesc('updated_at')
            ->get();

        $items = $tasks->map(function (TessaTask $t) {
            $isPending = $t->pending_extension_days !== null;
            $days = $isPending ? $t->pending_extension_days : $t->extension_notice_days;
            $proposedDeadline = $isPending && $t->deadline
                ? $t->deadline->copy()->addDays($days)
                : null;

            return [
                'id' => $t->id,
                'title' => $t->title,
                'assignee' => $t->assignedTo ? ['id' => $t->assignedTo->id, 'name' => $t->assignedTo->name] : null,
                'kind' => $isPending ? 'approval' : 'notice',
                'days' => $days,
                'extension_count' => (int) $t->deadline_extension_count,
                'original_deadline' => $t->original_deadline?->toIso8601String(),
                'deadline' => $t->deadline?->toIso8601String(),
                'proposed_deadline' => $proposedDeadline?->toIso8601String(),
            ];
        })->values();

        return response()->json(['items' => $items]);
    }

    /**
     * Tasks the current user assigned that an assignee marked completed and
     * are now waiting for the reporter to verify & close. Surfaced as the
     * "Awaiting verification" dashboard section.
     */
    public function verificationInbox(Request $request): JsonResponse
    {
        $user = $request->user();

        $tasks = TessaTask::where('assigned_by', $user->id)
            ->where('status', 'completed')
            ->with('assignedTo:id,name')
            ->orderByDesc('completed_at')
            ->get();

        $items = $tasks->map(fn (TessaTask $t) => [
            'id' => $t->id,
            'title' => $t->title,
            'assignee' => $t->assignedTo ? ['id' => $t->assignedTo->id, 'name' => $t->assignedTo->name] : null,
            'completed_at' => $t->completed_at?->toIso8601String(),
            'proof_note' => $t->proof_note,
        ])->values();

        return response()->json(['items' => $items]);
    }

    public function nudge(TessaTask $task, Request $request): JsonResponse
    {
        $task->load('assignedTo');

        if (! $task->assignedTo) {
            return response()->json(['error' => 'Task has no assignee'], 422);
        }

        if (in_array($task->status, ['completed', 'closed', 'cancelled'])) {
            return response()->json(['error' => 'Task is already ' . $task->status], 422);
        }

        app(TessaTaskService::class)->nudge($task, $request->user());

        return response()->json(['ok' => true, 'message' => 'Nudge sent via Slack']);
    }

    public function verify(TessaTask $task, Request $request): JsonResponse
    {
        $user = $request->user();

        if ($task->assigned_by !== $user->id && $task->shared_assigned_by !== $user->id) {
            return response()->json(['error' => 'Only the reporter or shared assigner can verify and close this task'], 403);
        }

        if ($task->status !== 'completed') {
            return response()->json(['error' => 'Only tasks awaiting verification can be closed'], 422);
        }

        $task->update([
            'status' => 'closed',
            'closed_at' => now(),
            'closed_by' => $user->id,
        ]);

        $task->load(['assignedBy', 'assignedTo']);
        app(TessaTaskService::class)->notifyClosed($task, $user);

        ActivityLogService::log(
            $user->id,
            'task_verified_closed',
            "{$user->name} verified and closed \"{$task->title}\"",
        );

        return response()->json([
            'ok' => true,
            'task' => $task->fresh()->load(['assignedBy:id,name', 'assignedTo:id,name', 'sharedAssignedBy:id,name']),
            'message' => 'Task verified and closed.',
        ]);
    }

    public function confirmFormSubmission(TessaTask $task, Request $request): JsonResponse
    {
        $user = $request->user();

        if ($task->assigned_to !== $user->id) {
            return response()->json(['error' => 'Only the assignee can confirm form submission'], 403);
        }

        if (empty($task->requires_form_url)) {
            return response()->json(['error' => 'This task has no form/sheet requirement'], 422);
        }

        $validated = $request->validate([
            'note' => 'nullable|string|max:2000',
        ]);

        $task->update([
            'proof_submitted_at' => now(),
            'proof_note' => trim((string) ($validated['note'] ?? '')) ?: null,
        ]);

        ActivityLogService::log(
            $user->id,
            'task_form_confirmed',
            "{$user->name} confirmed form submission on \"{$task->title}\"",
        );

        return response()->json([
            'ok' => true,
            'task' => $task->fresh()->load(['assignedBy:id,name', 'assignedTo:id,name', 'sharedAssignedBy:id,name']),
            'message' => 'Form submission recorded. You can now mark the task complete.',
        ]);
    }

    public function reopen(TessaTask $task, Request $request): JsonResponse
    {
        $user = $request->user();

        if ($task->assigned_by !== $user->id && $task->shared_assigned_by !== $user->id) {
            return response()->json(['error' => 'Only the reporter or shared assigner can reopen this task'], 403);
        }

        if (! in_array($task->status, ['completed', 'closed'], true)) {
            return response()->json(['error' => 'Only completed or closed tasks can be reopened'], 422);
        }

        $validated = $request->validate([
            'reason' => 'required|string|max:1000',
        ]);

        $reason = trim($validated['reason']);

        $task->update([
            'status' => 'in_progress',
            'completed_at' => null,
            'closed_at' => null,
            'closed_by' => null,
            'reopen_count' => ($task->reopen_count ?? 0) + 1,
            'reopen_reason' => $reason,
        ]);

        $task->load(['assignedBy', 'assignedTo']);
        app(TessaTaskService::class)->notifyReopened($task, $user, $reason);

        ActivityLogService::log(
            $user->id,
            'task_reopened',
            "{$user->name} reopened \"{$task->title}\"",
        );

        return response()->json([
            'ok' => true,
            'task' => $task->fresh()->load(['assignedBy:id,name', 'assignedTo:id,name', 'sharedAssignedBy:id,name']),
            'message' => 'Task reopened. Assignee notified.',
        ]);
    }
}
