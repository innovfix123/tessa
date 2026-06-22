<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class CreateScheduledMeetingTool extends Tool
{
    public function name(): string { return 'create_scheduled_meeting'; }
    public function description(): string
    {
        return 'Create a scheduled meeting and send the invites (this path bypasses the Slack quiet window). Use analyze_meeting_schedule first to pick a slot.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string'],
                'date' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                'time' => ['type' => 'string', 'description' => 'e.g. 15:00'],
                'attendees' => ['type' => 'array', 'items' => ['type' => 'integer']],
            ],
            'required' => ['title', 'date', 'time', 'attendees'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::post('/meetings/schedule/create', $args, $user);
    }
}
