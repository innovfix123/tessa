<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class SkipScheduledMeetingTool extends Tool
{
    public function name(): string { return 'skip_scheduled_meeting'; }
    public function description(): string
    {
        return 'Skip one occurrence of a scheduled meeting, with an optional reason.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'meeting_key' => ['type' => 'string'],
                'date' => ['type' => 'string', 'description' => 'Occurrence date to skip, YYYY-MM-DD.'],
                'reason' => ['type' => 'string'],
            ],
            'required' => ['meeting_key', 'date'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::post('/meetings/schedule/skip', $args, $user);
    }
}
