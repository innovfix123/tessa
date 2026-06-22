<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class UpdateSquadTool extends Tool
{
    public function name(): string { return 'update_squad'; }
    public function description(): string
    {
        return 'Edit a squad — its name, description, lead, or active status.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'squad_id' => ['type' => 'integer'],
                'name' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'lead_user_id' => ['type' => 'integer'],
                'is_active' => ['type' => 'boolean'],
            ],
            'required' => ['squad_id'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        $id = $args['squad_id'];
        unset($args['squad_id']);
        return ApiSubRequest::put("/squads/{$id}", $args, $user);
    }
}
