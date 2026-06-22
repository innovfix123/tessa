<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class ListScheduledMeetingsTool extends Tool
{
    public function name(): string { return 'list_scheduled_meetings'; }
    public function description(): string
    {
        return 'List the upcoming scheduled (one-off) meetings.';
    }
    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::get('/meetings/schedule/list', [], $user);
    }
}
