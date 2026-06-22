<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class ListTeamActionNeededTool extends Tool
{
    public function name(): string { return 'list_team_action_needed'; }
    public function description(): string
    {
        return 'For managers: list tasks where their subordinates owe a response. Returns nothing for individual contributors.';
    }
    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::get('/tessa/tasks/team-action-needed', [], $user);
    }
}
