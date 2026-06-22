<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class CreateMeetingTool extends Tool
{
    public function name(): string { return 'create_meeting'; }
    public function description(): string
    {
        return 'Schedule a meeting on the signed-in user\'s portal. time must look like "10:30 AM". '
            .'Use list_employees for ownerId / attendee ids; ownerId is usually yourself.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string'],
                'ownerId' => ['type' => 'integer', 'description' => 'User id of the meeting owner (usually yourself).'],
                'time' => ['type' => 'string', 'description' => 'Start time, e.g. "10:30 AM" (h:mm AM/PM).'],
                'recurrence' => ['type' => 'string', 'enum' => ['daily_weekdays', 'weekly', 'none', 'tue_to_fri', 'mon_thu', 'mon_wed_fri'], 'description' => 'How often it repeats (default none).'],
                'dayOfWeek' => ['type' => 'string', 'enum' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'], 'description' => 'Day, for weekly recurrence.'],
                'attendees' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Optional attendee user ids.'],
                'agendaTemplateId' => ['type' => 'integer', 'description' => 'Optional agenda template to attach.'],
            ],
            'required' => ['title', 'ownerId', 'time'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        // MeetingController::store dispatches on `action`; `portal` is read from
        // the (JSON) body via input(), so inject the user's own portal here.
        $args['action'] = 'add';
        $args['portal'] = $user->role;
        return ApiSubRequest::post('/meetings', $args, $user);
    }
}
