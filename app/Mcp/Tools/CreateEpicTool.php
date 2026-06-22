<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class CreateEpicTool extends Tool
{
    public function name(): string { return 'create_epic'; }
    public function description(): string
    {
        return 'Create an epic (a large body of work that groups stories) under a project/squad.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'project_id' => ['type' => 'integer'],
                'squad_id' => ['type' => 'integer'],
                'owner_id' => ['type' => 'integer'],
                'priority' => ['type' => 'string', 'description' => 'Use list_epics to see valid priorities.'],
                'target_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
            ],
            'required' => ['title'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::post('/epics', $args, $user);
    }
}
