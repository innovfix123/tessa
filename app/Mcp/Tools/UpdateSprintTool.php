<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class UpdateSprintTool extends Tool
{
    public function name(): string { return 'update_sprint'; }
    public function description(): string
    {
        return 'Edit a sprint — its name, goal, dates, project, or capacity.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'sprint_id' => ['type' => 'integer'],
                'name' => ['type' => 'string'],
                'goal' => ['type' => 'string'],
                'start_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                'end_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                'project_id' => ['type' => 'integer'],
                'capacity_hours' => ['type' => 'integer'],
            ],
            'required' => ['sprint_id'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        $id = $args['sprint_id'];
        unset($args['sprint_id']);
        return ApiSubRequest::put("/sprints/{$id}", $args, $user);
    }
}
