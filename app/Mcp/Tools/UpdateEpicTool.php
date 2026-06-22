<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class UpdateEpicTool extends Tool
{
    public function name(): string { return 'update_epic'; }
    public function description(): string
    {
        return 'Edit an epic — its title, description, squad, or status.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'epic_id' => ['type' => 'integer'],
                'title' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'squad_id' => ['type' => 'integer'],
                'status' => ['type' => 'string', 'description' => 'Use list_epics to see valid statuses.'],
            ],
            'required' => ['epic_id'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        $id = $args['epic_id'];
        unset($args['epic_id']);
        return ApiSubRequest::put("/epics/{$id}", $args, $user);
    }
}
