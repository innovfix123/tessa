<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class AnalyzeMeetingScheduleTool extends Tool
{
    public function name(): string { return 'analyze_meeting_schedule'; }
    public function description(): string
    {
        return 'Dry-run a meeting schedule: given a title, attendees, date and optional time, get suggested slots and conflicts before creating it.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string'],
                'attendee_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                'date' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                'time' => ['type' => 'string', 'description' => 'e.g. 15:00'],
                'time_mode' => ['type' => 'string', 'enum' => ['flexible', 'fixed']],
                'duration' => ['type' => 'integer', 'description' => 'Minutes (15-120).'],
            ],
            'required' => ['title', 'attendee_ids', 'date'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::post('/meetings/schedule/analyze', $args, $user);
    }
}
