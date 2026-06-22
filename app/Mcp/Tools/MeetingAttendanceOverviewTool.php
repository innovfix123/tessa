<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class MeetingAttendanceOverviewTool extends Tool
{
    public function name(): string { return 'meeting_attendance_overview'; }
    public function description(): string
    {
        return 'Get the cross-meeting attendance overview (who attended which meetings), optionally over a date range.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'from' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                'to' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
            ],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::get('/meeting-attendance/overview', $args, $user);
    }
}
