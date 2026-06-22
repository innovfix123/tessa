<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class ListMeetingsTool extends Tool
{
    public function name(): string { return 'list_meetings'; }
    public function description(): string
    {
        return 'List the meetings visible to you — your portal\'s recurring meetings plus any you own or attend. Each row includes the meeting id (for get_meeting / save_meeting_note) and its meetingKey, day, and time.';
    }
    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::get('/meetings', [], $user);
    }
}
