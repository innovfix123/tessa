<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\Meeting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;

class SaveMeetingNoteTool extends Tool
{
    public function name(): string { return 'save_meeting_note'; }
    public function description(): string
    {
        return 'Save (create-or-replace) a meeting\'s minutes (MOM) for a single occurrence AND auto-fill '
            .'that occurrence\'s agenda answers from the minutes — same as the portal\'s "Save Minutes" button. '
            .'meeting_id comes from list_meetings. For daily/multi-day meetings each weekday is a SEPARATE slot, '
            .'so pass `date` (YYYY-MM-DD, the day the meeting happened, IST) to store the note on the right day; '
            .'`date` defaults to today.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'meeting_id' => ['type' => ['integer', 'string'], 'description' => 'Meeting id or meetingKey (from list_meetings)'],
                'content' => ['type' => 'string', 'description' => 'Markdown / plain text body of the minutes'],
                'date' => ['type' => 'string', 'description' => 'The date the meeting occurred, YYYY-MM-DD in IST. Sets the week and (for daily/multi-day meetings) which weekday slot the note is stored under. Defaults to today.'],
                'week_key' => ['type' => 'string', 'description' => 'Optional. Monday of the week (YYYY-MM-DD) or ISO week (2026-W19). Ignored when `date` is given — prefer `date`.'],
            ],
            'required' => ['meeting_id', 'content'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        // MeetingNoteController resolves the meeting by its meetingKey (slug),
        // NOT the numeric id — passing the id 404s. If we were given a number,
        // translate it to the key via the meetings list.
        $baseKey = (string) $args['meeting_id'];
        if (is_int($args['meeting_id']) || ctype_digit($baseKey)) {
            try {
                $list = ApiSubRequest::get('/meetings', [], $user);
                foreach ($list['items'] ?? [] as $m) {
                    if ((int) ($m['id'] ?? 0) === (int) $args['meeting_id']) {
                        $baseKey = $m['meetingKey'] ?? $m['meeting_key'] ?? $baseKey;
                        break;
                    }
                }
            } catch (\Throwable $e) {
                // Fall through with the original ref.
            }
        }

        // Resolve the target week + weekday. A `date` is unambiguous (it pins both
        // the week and the weekday slot), so it wins. Otherwise fall back to an
        // explicit week_key (with today's weekday) or just today.
        [$weekKey, $dayName] = $this->resolveTarget($args);

        // For daily/multi-day meetings the note lives in a day-suffixed slot
        // (Tue–Fri). Compute it from the meeting's real recurrence so a Friday
        // MOM lands on `<key>-fri`, not the bare (Monday) key.
        $meeting = Meeting::where('meeting_key', $baseKey)->first();
        $meetingKey = $meeting ? $meeting->effectiveKeyForDay($dayName) : $baseKey;

        $saved = ApiSubRequest::post('/meeting-notes', [
            'action' => 'save',
            'meetingId' => $meetingKey,
            'weekKey' => $weekKey,
            'content' => $args['content'],
        ], $user);

        // Mirror the portal: after saving minutes, auto-fill the agenda answers
        // from them. Best-effort — a failed/credit-less AI call must NOT fail the
        // save (the minutes are the source of truth; the agenda is recoverable in
        // the portal). Skip entirely when there's no content to extract from.
        if (trim((string) ($args['content'] ?? '')) !== '') {
            try {
                $fill = ApiSubRequest::post('/agenda-sections', [
                    'action' => 'auto_fill',
                    'meetingId' => $meetingKey,
                    'weekKey' => $weekKey,
                ], $user);
                $saved['agenda'] = ['attempted' => true, 'filled' => $fill['filled'] ?? null];
            } catch (\Throwable $e) {
                $saved['agenda'] = [
                    'attempted' => true,
                    'error' => 'Agenda auto-fill failed — open the meeting in the portal and click Save Minutes to fill it.',
                ];
            }
        }

        return $saved;
    }

    /**
     * @return array{0:string,1:string} [weekKey (Monday Y-m-d), dayName (e.g. 'Friday')]
     */
    private function resolveTarget(array $args): array
    {
        $date = trim((string) ($args['date'] ?? ''));
        if ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $target = Carbon::parse($date, 'Asia/Kolkata');

            return [$target->copy()->startOfWeek(Carbon::MONDAY)->format('Y-m-d'), $target->format('l')];
        }

        $weekKeyArg = trim((string) ($args['week_key'] ?? ''));
        if ($weekKeyArg !== '') {
            return [$this->normalizeWeekKey($weekKeyArg), $this->defaultDayName()];
        }

        $now = Carbon::now('Asia/Kolkata');

        return [$now->copy()->startOfWeek(Carbon::MONDAY)->format('Y-m-d'), $this->defaultDayName()];
    }

    /**
     * Today's weekday (IST). Weekend → Friday, so a Sat/Sun save of a weekday-only
     * meeting targets the last occurrence rather than minting a nonexistent -sat slot.
     */
    private function defaultDayName(): string
    {
        $day = Carbon::now('Asia/Kolkata')->format('l');

        return in_array($day, ['Saturday', 'Sunday'], true) ? 'Friday' : $day;
    }

    /**
     * MeetingNoteController requires a calendar date (YYYY-MM-DD, the week's
     * Monday). Accept an ISO week (2026-W19) too and convert it, so either
     * form the model sends works.
     */
    private function normalizeWeekKey(string $value): string
    {
        if (preg_match('/^(\d{4})-W(\d{2})$/', $value, $m)) {
            return Carbon::now()
                ->setISODate((int) $m[1], (int) $m[2])
                ->startOfWeek(Carbon::MONDAY)
                ->format('Y-m-d');
        }

        return $value;
    }
}
