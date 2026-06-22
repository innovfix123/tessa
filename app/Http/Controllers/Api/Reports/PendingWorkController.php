<?php

namespace App\Http\Controllers\Api\Reports;

use App\Http\Controllers\Controller;
use App\Models\DailyReport;
use App\Models\DailySignin;
use App\Models\DailySignoff;
use App\Models\DiscussionPoint;
use App\Models\KpiDefinition;
use App\Models\Meeting;
use App\Models\MeetingNote;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\User;
use App\Helpers\DateHelper;
use App\Services\SignoffStatusService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PendingWorkController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $dateStr = trim((string) $request->query('date', ''));
        if ($dateStr === '') {
            $dateStr = Carbon::now('Asia/Kolkata')->format('Y-m-d');
        } elseif (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStr)) {
            return response()->json(['error' => 'date must be YYYY-MM-DD'], 422);
        }

        $selectedDate = DateHelper::parse($dateStr);
        $dayName = $selectedDate->format('l');
        $weekKey = $selectedDate->copy()->startOfWeek(Carbon::MONDAY)->format('Y-m-d');
        $now = Carbon::now('Asia/Kolkata');
        $isToday = $selectedDate->isSameDay($now);

        $dailyReportDateStr = trim((string) $request->query('daily_report_date', ''));
        if ($dailyReportDateStr === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $dailyReportDateStr)) {
            $dailyReportDateStr = $dateStr;
        }
        $dailyReportWeekKey = Carbon::parse($dailyReportDateStr)->copy()->startOfWeek(Carbon::MONDAY)->format('Y-m-d');

        $dailyReport = [];
        $dailyReportDate = Carbon::parse($dailyReportDateStr);
        if (! $dailyReportDate->isWeekend()) {
            $allFields = KpiDefinition::withTrashed()
                ->visibleForWeek($dailyReportWeekKey)
                ->where('user_id', $user->id)
                ->whereNotIn('field_key', ['_group_init', '_person_init', '_placeholder'])
                ->where('optional', false)
                ->orderBy('group_name')
                ->orderBy('sort_order')
                ->get();

            $filled = DailyReport::where('user_id', $user->id)
                ->where('report_date', $dailyReportDateStr)
                ->pluck('value', 'field_key');

            foreach ($allFields as $f) {
                $val = (string) ($filled[$f->field_key] ?? '');
                if (trim($val) !== '') {
                    continue;
                }
                $dailyReport[] = [
                    'field_key' => $f->field_key,
                    'field_label' => $f->field_label,
                    'group' => $f->group_name ?: 'Metrics',
                    'current_value' => '',
                    'report_date' => $dailyReportDateStr,
                ];
            }
        }

        $meetings = $this->getMeetingsForUser($user, $dayName);
        $agendaItems = [];
        $notesItems = [];

        foreach ($meetings as $meeting) {
            if ($meeting->owner_id !== $user->id) {
                continue;
            }
            $effId = $this->effectiveMeetingId($meeting, $dayName);
            $timePassed = $this->meetingTimeHasPassed($meeting->time, $selectedDate, $isToday, $now);

            if ($timePassed) {
                $points = DiscussionPoint::where('meeting_id', $effId)
                    ->where('week_key', $weekKey)
                    ->get();
                foreach ($points->filter(fn ($p) => trim((string) ($p->answer ?? '')) === '') as $p) {
                    $agendaItems[] = [
                        'dp_id' => $p->id,
                        'meeting_id' => $effId,
                        'week_key' => $weekKey,
                        'meeting_title' => $meeting->title,
                        'meeting_time' => $meeting->time,
                        'meeting_date' => $dateStr,
                        'question' => $p->question,
                    ];
                }
            }

            if ($timePassed) {
                $note = MeetingNote::where('meeting_id', $effId)
                    ->where('week_key', $weekKey)
                    ->first();
                if (! $note || trim((string) ($note->content ?? '')) === '') {
                    $notesItems[] = [
                        'meeting_id' => $effId,
                        'week_key' => $weekKey,
                        'meeting_title' => $meeting->title,
                        'meeting_time' => $meeting->time,
                        'meeting_date' => $dateStr,
                        'current_content' => $note?->content ?? '',
                    ];
                }
            }

        }

        $tickets = Ticket::with('reporter:id,name')
            ->where('assignee_id', $user->id)
            ->whereIn('status', [Ticket::STATUS_OPEN, Ticket::STATUS_IN_PROGRESS])
            ->orderByRaw("FIELD(priority, 'high', 'medium', 'low')")
            ->orderBy('created_at')
            ->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'title' => $t->title,
                'category' => $t->category,
                'priority' => $t->priority,
                'status' => $t->status,
                'reporter_name' => $t->reporter?->name,
                'created_at' => $t->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        $signoff = DailySignoff::where('user_id', $user->id)
            ->where('signoff_date', $dateStr)
            ->first();

        $signin = DailySignin::where('user_id', $user->id)
            ->where('signin_date', $dateStr)
            ->first();

        $signedIn = (bool) $signin;
        $signedInAtIso = $signin ? $signin->signed_in_at->toIso8601String() : null;

        $serverToday = Carbon::now('Asia/Kolkata')->format('Y-m-d');
        $signoffStatus = SignoffStatusService::getStatus($user, $dateStr);
        $canSignOff = ($signoffStatus['ok'] ?? false) && ($signoffStatus['canSignOff'] ?? false);

        return response()->json([
            'ok' => true,
            'date' => $dateStr,
            'dailyReportDate' => $dailyReportDateStr,
            'serverToday' => $serverToday,
            'signedIn' => $signedIn,
            'signedInAt' => $signedInAtIso,
            'dashboardSignedIn' => (bool) $signin,
            'signedOff' => (bool) $signoff,
            'signedOffAt' => $signoff?->signed_off_at?->toIso8601String(),
            'canSignOffFromPanel' => $canSignOff,
            'dailyReport' => $dailyReport,
            'agenda' => $agendaItems,
            'notes' => $notesItems,
            'actionItems' => [],
            'carriedForward' => [],
            'tickets' => $tickets,
        ]);
    }

    private function resolveBaseMeetingKey(string $meetingId): string
    {
        $suffixes = ['-tue', '-wed', '-thu', '-fri'];
        foreach ($suffixes as $suffix) {
            if (str_ends_with($meetingId, $suffix)) {
                return substr($meetingId, 0, -strlen($suffix));
            }
        }

        return $meetingId;
    }

    private function effectiveMeetingId(Meeting $meeting, string $dayName): string
    {
        if (($meeting->recurrence ?? '') !== 'daily_weekdays' || $dayName === 'Monday') {
            return $meeting->meeting_key;
        }
        $suffix = '-'.strtolower(substr($dayName, 0, 3));

        return $meeting->meeting_key.$suffix;
    }

    private function meetingTimeHasPassed(string $timeStr, Carbon $selectedDate, bool $isToday, Carbon $now): bool
    {
        if (! $isToday) {
            return $selectedDate->isPast();
        }
        $parsed = $this->parseMeetingTime($timeStr);
        if ($parsed === null) {
            return false;
        }
        $meetingDateTime = $selectedDate->copy()->shiftTimezone('Asia/Kolkata')->setTimeFromTimeString($parsed);

        return $now->greaterThan($meetingDateTime);
    }

    private function parseMeetingTime(string $timeStr): ?string
    {
        $timeStr = trim($timeStr);
        if (preg_match('/^(\d{1,2}):(\d{2})\s*(AM|PM)$/i', $timeStr, $m)) {
            $h = (int) $m[1];
            $min = (int) $m[2];
            $ampm = strtoupper($m[3]);
            if ($ampm === 'PM' && $h < 12) {
                $h += 12;
            } elseif ($ampm === 'AM' && $h === 12) {
                $h = 0;
            }

            return sprintf('%02d:%02d:00', $h, $min);
        }

        return null;
    }

    private function getMeetingsForUser(User $user, string $dayName)
    {
        return Meeting::where(function ($q) use ($user) {
            $userId = $user->id;
            if ($user->role === Role::SLUG_PRODUCT_MANAGER) {
                $q->where('owner_id', $userId)
                    ->orWhereJsonContains('attendees', $userId);

                return;
            }
            $q->where('portal', $user->role)
                ->orWhere('owner_id', $userId)
                ->orWhereJsonContains('attendees', $userId);
        })
            ->where(function ($q) use ($dayName) {
                // monthly_first only occurs the first such weekday of the month — exclude here.
                $q->where(function ($w) use ($dayName) {
                    $w->where('day_of_week', $dayName)->where('recurrence', '!=', 'monthly_first');
                });
                if (in_array($dayName, ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'], true)) {
                    $q->orWhere('recurrence', 'daily_weekdays');
                }
            })
            ->orderBy('time')
            ->orderBy('id')
            ->get();
    }
}
