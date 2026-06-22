<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class DeleteScheduledMeetingTool extends Tool
{
    public function name(): string { return 'delete_scheduled_meeting'; }
    public function description(): string
    {
        return 'Delete a scheduled meeting by its id.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['id' => ['type' => 'integer']],
            'required' => ['id'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::post('/meetings/schedule/delete', ['id' => $args['id']], $user);
    }
}
