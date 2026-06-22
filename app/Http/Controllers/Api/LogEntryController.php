<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\LeaveType;
use App\Models\LogEntry;
use App\Models\Meeting;
use App\Models\TaskParticipant;
use App\Models\TessaTask;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\LeaveService;
use App\Services\MeetingSchedulerService;
use App\Services\TessaAIService;
use App\Services\TessaTaskService;
use App\Support\LogActivityCatalog;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class LogEntryController extends Controller
{
    /** Recent activity rows loaded before collapse (newest first). */
    private const ACTIVITY_FETCH_LIMIT = 1500;

    /** Max activity items in the timeline after collapse (newest kept). */
    private const ACTIVITY_TIMELINE_LIMIT = 500;

    /** Max upcoming meeting occurrences through Friday. */
    private const UPCOMING_MEETINGS_LIMIT = 100;

    private const MULTI_DAY_RECURRENCES = [
        'daily_weekdays' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'],
        'tue_to_fri' => ['Tuesday', 'Wednesday', 'Thursday', 'Friday'],
        'mon_thu' => ['Monday', 'Thursday'],
        'mon_wed_fri' => ['Monday', 'Wednesday', 'Friday'],
    ];

    private const DAY_MAP = [
        'Monday' => 0,
        'Tuesday' => 1,
        'Wednesday' => 2,
        'Thursday' => 3,
        'Friday' => 4,
    ];

    public function index(Request $request): JsonResponse
    {
        $uid = (int) $request->user()->id;

        $entries = LogEntry::where('user_id', $uid)
            ->orderBy('created_at')
            ->get()
            ->map(fn (LogEntry $e) => array_merge($this->format($e), ['type' => 'entry', 'tense' => 'past']));

        $activitySince = now('Asia/Kolkata')->subDays(30)->format('Y-m-d H:i:s');

        $activityLogs = ActivityLog::query()
            ->where('created_at', '>=', $activitySince)
            ->whereIn('action', LogActivityCatalog::INCLUDED_ACTIONS)
            ->where(function ($q) use ($uid) {
                $q->where('user_id', $uid)
                    ->orWhere('metadata->target_user_id', $uid)
                    // Legacy task rows before target_user_id metadata
                    ->orWhere(function ($q2) use ($uid) {
                        $q2->whereIn('action', ['task_assigned', 'task_reassigned'])
                            ->where('description', 'like', '%user #'.$uid.'%');
                    });
            })
            ->orderByDesc('created_at')
            ->limit(self::ACTIVITY_FETCH_LIMIT)
            ->get();

        $names = $this->resolveActivityNames($activityLogs, $uid);
        $activityLogs = collect(LogActivityCatalog::collapse($activityLogs, $uid, $names));

        $activityItems = $activityLogs
            ->map(function (ActivityLog $a) use ($names, $uid) {
                return [
                    'id' => 'act_'.$a->id,
                    'type' => 'activity',
                    'source' => 'activity',
                    'group' => LogActivityCatalog::group($a->action),
                    'content' => LogActivityCatalog::describe($a, $names, $uid),
                    'created_at' => $a->created_at->utc()->toIso8601String(),
                    'tense' => 'past',
                ];
            })
            ->sortByDesc('created_at')
            ->take(self::ACTIVITY_TIMELINE_LIMIT)
            ->sortBy('created_at')
            ->values();

        $upcomingMeetings = collect($this->buildUpcomingMeetings($uid));
        $taskItems = collect($this->buildTaskItems($uid));

        // Always include every manual/Slack entry; cap only portal activity (newest first).
        $merged = $entries
            ->concat($activityItems)
            ->concat($taskItems)
            ->concat($upcomingMeetings)
            ->sortBy(fn (array $e) => strtotime($e['created_at'] ?? ''))
            ->values();

        return response()->json(['entries' => $merged]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'content' => 'required|string|max:10000',
            'source' => ['nullable', Rule::in([LogEntry::SOURCE_TEXT, LogEntry::SOURCE_VOICE])],
        ]);

        $content = trim($data['content']);

        $analysis = [
            'log' => true,
            'category' => LogEntry::CATEGORY_NOTE,
            'content' => $content,
        ];

        try {
            $analysis = (new TessaAIService)->analyzeLogEntry($content, 'logs_text', $request->user()->id);
        } catch (\Throwable $e) {
            // analyzeLogEntry falls back internally; keep defaults as a safety net
        }

        if (! ($analysis['log'] ?? true)) {
            return response()->json(['skipped' => true, 'reason' => 'not_meaningful']);
        }

        $entry = LogEntry::create([
            'user_id' => $request->user()->id,
            'content' => $analysis['content'] ?? $content,
            'category' => $analysis['category'] ?? LogEntry::CATEGORY_NOTE,
            'source' => $data['source'] ?? LogEntry::SOURCE_TEXT,
        ]);

        return response()->json(['entry' => $this->format($entry)], 201);
    }

    public function assignTask(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'text' => 'required|string|max:2000',
        ]);

        $text = trim($data['text']);
        $parsed = (new TessaAIService)->parseTaskFromText($text, $user->id, $user->name);

        $assigneeName = $parsed['assignee'] ?? null;
        $assigneeId = null;

        if ($assigneeName) {
            $resolved = app(MeetingSchedulerService::class)->resolveAttendees([$assigneeName]);
            if (! empty($resolved['resolved'])) {
                $assigneeId = (int) $resolved['resolved'][0]['id'];
            }
        }

        if (! $assigneeId) {
            return response()->json([
                'error' => 'assignee_required',
                'message' => 'Please tell whom to assign the task to.',
            ], 422);
        }

        $dueDate = $parsed['due_date'] ?? null;
        if (! $dueDate || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) {
            return response()->json([
                'error' => 'due_date_required',
                'message' => 'Please add a due date (e.g. "by Friday" or "tomorrow").',
            ], 422);
        }

        $title = $parsed['title'] ?: $text;
        $priority = $parsed['priority'] ?? 'medium';

        $task = app(TessaTaskService::class)->createAndNotify(
            $user,
            $assigneeId,
            $title,
            null,
            $priority,
            $dueDate
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

        ActivityLogService::log(
            $user->id,
            'task_assigned',
            "{$user->name} assigned task \"{$task->title}\" to user #{$task->assigned_to}",
            'tessa_task',
            $task->id,
            ['target_user_id' => $task->assigned_to],
        );

        $task->load(['assignedTo:id,name']);

        return response()->json([
            'task' => [
                'id' => $task->id,
                'title' => $task->title,
                'assignee_name' => $task->assignedTo?->name ?? '',
                'deadline' => $task->deadline?->toDateString(),
                'priority' => $task->priority,
            ],
        ], 201);
    }

    public function requestLeave(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'text' => 'required|string|max:2000',
        ]);

        $text = trim($data['text']);
        $parsed = (new TessaAIService)->parseLeaveFromText($text, $user->gender, $user->name);

        $slug = $parsed['leave_type'] ?? null;
        $leaveType = null;
        if ($slug) {
            $leaveType = LeaveType::query()
                ->where('is_active', true)
                ->where('slug', $slug)
                ->where(function ($q) use ($user) {
                    $q->whereNull('gender_restricted')->orWhere('gender_restricted', $user->gender);
                })
                ->first();
        }

        if (! $leaveType) {
            return response()->json([
                'error' => 'leave_type_required',
                'message' => 'Which leave type? e.g. sick, casual, emergency, or WFH.',
            ], 422);
        }

        $startDate = $parsed['start_date'] ?? null;
        if (! $startDate || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
            return response()->json([
                'error' => 'start_date_required',
                'message' => 'When? Add a date like "tomorrow" or "June 5".',
            ], 422);
        }

        $endDate = $parsed['end_date'] ?: $startDate;

        try {
            $leave = app(LeaveService::class)->applyLeave(
                $user,
                $leaveType->slug,
                $startDate,
                $endDate,
                $parsed['reason'] ?? null,
                'web',
                null,
                $parsed['from_time'] ?? null,
                $parsed['to_time'] ?? null,
                null
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $leave->load('leaveType:id,name,slug');

        $typeName = $leave->leaveType?->name ?? 'leave';
        $range = $leave->start_date->format('Y-m-d');
        if ($leave->end_date->format('Y-m-d') !== $range) {
            $range .= ' – '.$leave->end_date->format('Y-m-d');
        }

        ActivityLogService::log(
            $user->id,
            'leave_applied',
            "{$user->name} applied for {$typeName} ({$range})",
            'leave_request',
            $leave->id,
            ['status' => $leave->status, 'target_user_id' => $user->id],
        );

        return response()->json([
            'message' => $leave->status === 'approved'
                ? 'Leave auto-approved! Your manager has been notified.'
                : 'Leave submitted! Pending manager approval.',
            'leave' => [
                'id' => $leave->id,
                'type' => $typeName,
                'start_date' => $leave->start_date->format('Y-m-d'),
                'end_date' => $leave->end_date->format('Y-m-d'),
                'status' => $leave->status,
            ],
        ], 201);
    }

    public function update(Request $request, LogEntry $logEntry): JsonResponse
    {
        if ($logEntry->user_id !== $request->user()->id) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'content' => 'sometimes|required|string|max:10000',
            'category' => ['sometimes', Rule::in(LogEntry::CATEGORIES)],
        ]);

        if (isset($data['content'])) {
            $data['content'] = trim($data['content']);
        }

        $logEntry->update($data);

        return response()->json(['entry' => $this->format($logEntry->fresh())]);
    }

    public function destroy(Request $request, LogEntry $logEntry): JsonResponse
    {
        if ($logEntry->user_id !== $request->user()->id) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $logEntry->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * Whether the user has Slack connected. Connection alone drives sync —
     * connected means their own messages are auto-synced into Logs.
     */
    public function slackStatus(Request $request): JsonResponse
    {
        return response()->json([
            'connected' => $request->user()->hasSlackConnection(),
        ]);
    }

    private function format(LogEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'content' => $entry->content,
            'category' => $entry->category,
            'source' => $entry->source,
            'slack_permalink' => $entry->slack_permalink,
            'created_at' => $entry->created_at->toIso8601String(),
            'updated_at' => $entry->updated_at->toIso8601String(),
        ];
    }

    /**
     * Upcoming meeting occurrences from now through Friday (IST), for meetings the user owns or attends.
     *
     * @return list<array<string, mixed>>
     */
    private function buildUpcomingMeetings(int $uid): array
    {
        $now = Carbon::now('Asia/Kolkata');

        if ($now->isWeekend()) {
            return [];
        }

        $weekStart = $now->copy()->startOfWeek(Carbon::MONDAY);
        $fridayEnd = $weekStart->copy()->addDays(4)->endOfDay();
        $todayDate = $now->toDateString();
        $fridayDate = $weekStart->copy()->addDays(4)->toDateString();

        $meetings = Meeting::query()
            ->where(function ($q) use ($uid) {
                $q->where('owner_id', $uid)
                    ->orWhereJsonContains('attendees', $uid);
            })
            ->get();

        if ($meetings->isEmpty()) {
            return [];
        }

        $skipKeys = $meetings->pluck('meeting_key')->unique()->values()->all();
        $skipsByKey = DB::table('meeting_skips')
            ->whereIn('meeting_key', $skipKeys)
            ->get()
            ->groupBy('meeting_key')
            ->map(fn ($rows) => $rows->pluck('skip_date')->map(fn ($d) => Carbon::parse($d, 'Asia/Kolkata')->format('Y-m-d'))->all())
            ->all();

        $items = [];

        foreach ($meetings as $meeting) {
            $days = self::MULTI_DAY_RECURRENCES[$meeting->recurrence] ?? [$meeting->day_of_week];
            $meetingSkips = $skipsByKey[$meeting->meeting_key] ?? [];
            $startMinutes = $this->meetingTimeToMinutes($meeting->time);

            if ($startMinutes === null) {
                continue;
            }

            foreach ($days as $dayName) {
                $dayIndex = self::DAY_MAP[$dayName] ?? null;
                if ($dayIndex === null) {
                    continue;
                }

                $occurrenceDate = $weekStart->copy()->addDays($dayIndex)->format('Y-m-d');

                // monthly_first occurs only on the first <weekday> of the month (day 1–7).
                if ($meeting->recurrence === 'monthly_first' && (int) Carbon::parse($occurrenceDate, 'Asia/Kolkata')->day > 7) {
                    continue;
                }

                if ($occurrenceDate < $todayDate || $occurrenceDate > $fridayDate) {
                    continue;
                }

                if (in_array($occurrenceDate, $meetingSkips, true)) {
                    continue;
                }

                $occurrence = Carbon::parse($occurrenceDate, 'Asia/Kolkata')
                    ->startOfDay()
                    ->addMinutes($startMinutes);

                if ($occurrence->lte($now) || $occurrence->gt($fridayEnd)) {
                    continue;
                }

                $items[] = [
                    'id' => 'mtg_'.$meeting->id.'_'.$occurrenceDate,
                    'type' => 'meeting_upcoming',
                    'source' => 'meeting',
                    'tense' => 'future',
                    'group' => 'Meeting',
                    'title' => $meeting->title,
                    'meeting_time' => $meeting->time,
                    'content' => $meeting->title,
                    'created_at' => $occurrence->utc()->toIso8601String(),
                    '_sort' => $occurrence->timestamp,
                ];
            }
        }

        usort($items, fn ($a, $b) => $a['_sort'] <=> $b['_sort']);

        $items = array_slice($items, 0, self::UPCOMING_MEETINGS_LIMIT);

        return array_map(function ($item) {
            unset($item['_sort']);

            return $item;
        }, $items);
    }

    /**
     * Open tasks with deadlines: overdue (pinned just above NOW) and due through Friday (future).
     *
     * @return list<array<string, mixed>>
     */
    private function buildTaskItems(int $uid): array
    {
        $now = Carbon::now('Asia/Kolkata');
        $weekStart = $now->copy()->startOfWeek(Carbon::MONDAY);
        $fridayEnd = $weekStart->copy()->addDays(4)->endOfDay();

        $tasks = TessaTask::query()
            ->whereIn('status', TessaTask::ACTIVE_STATUSES)
            ->whereNotNull('deadline')
            ->where(function ($q) use ($uid) {
                $q->where('assigned_to', $uid)
                    ->orWhere('assigned_by', $uid);
            })
            ->with(['assignedBy:id,name', 'assignedTo:id,name'])
            ->orderBy('deadline')
            ->get();

        $overdue = [];
        $upcoming = [];

        foreach ($tasks as $task) {
            $deadline = $task->deadline->copy()->timezone('Asia/Kolkata');

            if ((int) $task->assigned_by === $uid && (int) $task->assigned_to === $uid) {
                $direction = 'self';
                $counterpart = '';
            } elseif ((int) $task->assigned_by === $uid) {
                $direction = 'out';
                $counterpart = $task->assignedTo?->name ?? '';
            } else {
                $direction = 'in';
                $counterpart = $task->assignedBy?->name ?? '';
            }

            $item = [
                'id' => 'task_'.$task->id,
                'type' => 'task_due',
                'source' => 'task',
                'group' => 'Task',
                'title' => $task->title,
                'content' => $task->title,
                'priority' => $task->priority ?? 'medium',
                'direction' => $direction,
                'counterpart' => $counterpart,
                'deadline' => $deadline->utc()->toIso8601String(),
            ];

            if ($deadline->lt($now)) {
                $item['tense'] = 'overdue';
                $item['_deadline_sort'] = $deadline->timestamp;
                $overdue[] = $item;
            } elseif (! $now->isWeekend() && $deadline->gt($now) && $deadline->lte($fridayEnd)) {
                $item['tense'] = 'future';
                $item['created_at'] = $deadline->utc()->toIso8601String();
                $upcoming[] = $item;
            }
        }

        usort($overdue, fn ($a, $b) => $a['_deadline_sort'] <=> $b['_deadline_sort']);

        $overdueCount = count($overdue);
        $overdueOut = [];
        foreach ($overdue as $i => $item) {
            unset($item['_deadline_sort']);
            // Stagger slightly before "now" so overdue cluster stays ordered and above future items.
            $item['created_at'] = $now->copy()
                ->subSeconds(max(1, $overdueCount - $i))
                ->utc()
                ->toIso8601String();
            $overdueOut[] = $item;
        }

        return array_merge($overdueOut, $upcoming);
    }

    private function meetingTimeToMinutes(?string $time): ?int
    {
        if (! $time) {
            return null;
        }

        $time = trim($time);

        if (preg_match('/^(\d{1,2}):(\d{2})$/', $time, $m)) {
            return (int) $m[1] * 60 + (int) $m[2];
        }

        if (preg_match('/^(\d{1,2}):(\d{2})\s*(AM|PM)$/i', $time, $m)) {
            $h = (int) $m[1];
            $min = (int) $m[2];
            $period = strtoupper($m[3]);
            if ($period === 'PM' && $h !== 12) {
                $h += 12;
            }
            if ($period === 'AM' && $h === 12) {
                $h = 0;
            }

            return $h * 60 + $min;
        }

        return null;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, ActivityLog>  $activityLogs
     * @return array<int, string>
     */
    private function resolveActivityNames($activityLogs, int $viewerId): array
    {
        $ids = [$viewerId];

        foreach ($activityLogs as $log) {
            $ids[] = (int) $log->user_id;
            if (preg_match_all('/\buser #(\d+)\b/', $log->description, $matches)) {
                foreach ($matches[1] as $idStr) {
                    $ids[] = (int) $idStr;
                }
            }
            $target = $log->metadata['target_user_id'] ?? null;
            if ($target) {
                $ids[] = (int) $target;
            }
        }

        $ids = array_values(array_unique(array_filter($ids)));

        return User::query()
            ->whereIn('id', $ids)
            ->pluck('name', 'id')
            ->all();
    }
}
