<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class RescheduleMeetingTool extends Tool
{
    public function name(): string { return 'reschedule_meeting'; }
    public function description(): string
    {
        return 'Reschedule a scheduled meeting occurrence to a new date.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'meeting_key' => ['type' => 'string'],
                'date' => ['type' => 'string', 'description' => 'New date, YYYY-MM-DD.'],
            ],
            'required' => ['meeting_key', 'date'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::post('/meetings/schedule/reschedule', $args, $user);
    }
}
