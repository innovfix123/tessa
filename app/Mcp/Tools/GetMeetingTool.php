<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Mcp\ToolException;
use App\Models\Meeting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;

class GetMeetingTool extends Tool
{
    public function name(): string { return 'get_meeting'; }
    public function description(): string
    {
        return 'Fetch a single meeting by id, plus a given day\'s latest note and action items. For daily/multi-day meetings the note is per-weekday — pass `date` (YYYY-MM-DD, IST; defaults to today) to read that day\'s minutes. Get the id from list_meetings.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'meeting_id' => ['type' => 'integer'],
                'date' => ['type' => 'string', 'description' => 'Day to read the note for, YYYY-MM-DD in IST. Defaults to today.'],
            ],
            'required' => ['meeting_id'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        // There is no single-meeting GET endpoint — /meetings ignores ?id and
        // returns the whole visible roster. So fetch the list and pick the one,
        // then enrich it with the day's note + action items.
        $id = (int) $args['meeting_id'];
        $list = ApiSubRequest::get('/meetings', [], $user);
        $meeting = null;
        foreach ($list['items'] ?? [] as $m) {
            if ((int) ($m['id'] ?? 0) === $id) { $meeting = $m; break; }
        }
        if (! $meeting) {
            throw new ToolException("Meeting {$id} not found, or not visible to you.", 404);
        }

        $key = $meeting['meetingKey'] ?? $meeting['meeting_key'] ?? null;

        // Resolve which day/week to read. For daily/multi-day meetings the MOM
        // lives in a day-suffixed slot, so read that — not the bare key — or
        // Tue–Fri would always return Monday's note.
        $date = trim((string) ($args['date'] ?? ''));
        if ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $target = Carbon::parse($date, 'Asia/Kolkata');
        } else {
            $target = Carbon::now('Asia/Kolkata');
        }
        $week = $target->copy()->startOfWeek(Carbon::MONDAY)->format('Y-m-d');
        $dayName = $target->format('l');
        if (in_array($dayName, ['Saturday', 'Sunday'], true)) {
            $dayName = 'Friday';
        }

        if ($key) {
            $model = Meeting::where('meeting_key', $key)->first();
            $noteKey = $model ? $model->effectiveKeyForDay($dayName) : $key;
            try {
                $note = ApiSubRequest::get('/meeting-notes', ['meeting_id' => $noteKey, 'week_key' => $week], $user);
                $meeting['latest_note'] = $note['note']['content'] ?? $note['content'] ?? $note['note'] ?? null;
            } catch (\Throwable $e) {
            }
            try {
                $actions = ApiSubRequest::get('/action-items', ['meeting_id' => $key], $user);
                $meeting['action_items'] = $actions['items'] ?? [];
            } catch (\Throwable $e) {
            }
        }

        return ['meeting' => $meeting];
    }
}
