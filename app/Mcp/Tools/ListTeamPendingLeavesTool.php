<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class ListTeamPendingLeavesTool extends Tool
{
    public function name(): string { return 'list_team_pending_leaves'; }
    public function description(): string
    {
        return 'For managers: list pending leave requests from their subordinates that need approval.';
    }
    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::get('/leave/team-pending', [], $user);
    }
}
