<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class DeleteMeetingTool extends Tool
{
    public function name(): string { return 'delete_meeting'; }
    public function description(): string
    {
        return 'Delete a meeting (and its notes, agenda points and action items). You must have edit access to it (owner). Get the meeting id from list_meetings.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['meeting_id' => ['type' => 'integer']],
            'required' => ['meeting_id'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::post('/meetings', ['action' => 'delete', 'id' => $args['meeting_id']], $user);
    }
}
