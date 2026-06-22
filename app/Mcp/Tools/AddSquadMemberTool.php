<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class AddSquadMemberTool extends Tool
{
    public function name(): string { return 'add_squad_member'; }
    public function description(): string
    {
        return 'Add a user to a squad, optionally as lead or member.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'squad_id' => ['type' => 'integer'],
                'user_id' => ['type' => 'integer'],
                'role_in_squad' => ['type' => 'string', 'enum' => ['lead', 'member']],
            ],
            'required' => ['squad_id', 'user_id'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        $id = $args['squad_id'];
        $payload = ['user_id' => $args['user_id']];
        if (isset($args['role_in_squad'])) {
            $payload['role_in_squad'] = $args['role_in_squad'];
        }
        return ApiSubRequest::post("/squads/{$id}/members", $payload, $user);
    }
}
