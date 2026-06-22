<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class RecordMeetingAttendanceTool extends Tool
{
    public function name(): string { return 'record_meeting_attendance'; }
    public function description(): string
    {
        return 'As the meeting owner, manually override one person\'s attendance for a meeting occurrence (present/absent).';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'meeting_id' => ['type' => 'string', 'description' => 'The meeting id / key.'],
                'date' => ['type' => 'string', 'description' => 'Occurrence date, YYYY-MM-DD.'],
                'user_id' => ['type' => 'integer'],
                'status' => ['type' => 'string', 'enum' => ['present', 'absent']],
            ],
            'required' => ['meeting_id', 'date', 'user_id', 'status'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        // Controller reads camelCase meetingId / userId from the body.
        return ApiSubRequest::post('/meeting-attendance', [
            'meetingId' => $args['meeting_id'],
            'date' => $args['date'],
            'userId' => $args['user_id'],
            'status' => $args['status'],
        ], $user);
    }
}
