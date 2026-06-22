<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class RemoveSquadMemberTool extends Tool
{
    public function name(): string { return 'remove_squad_member'; }
    public function description(): string
    {
        return 'Remove a user from a squad.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'squad_id' => ['type' => 'integer'],
                'user_id' => ['type' => 'integer'],
            ],
            'required' => ['squad_id', 'user_id'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::delete("/squads/{$args['squad_id']}/members/{$args['user_id']}", [], $user);
    }
}
