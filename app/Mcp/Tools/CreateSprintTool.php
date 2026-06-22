<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class CreateSprintTool extends Tool
{
    public function name(): string { return 'create_sprint'; }
    public function description(): string
    {
        return 'Create an agile sprint for a squad/project with a date range and optional capacity.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
                'goal' => ['type' => 'string'],
                'squad_id' => ['type' => 'integer'],
                'project_id' => ['type' => 'integer'],
                'start_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                'end_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD, after start_date'],
                'capacity_hours' => ['type' => 'integer'],
            ],
            'required' => ['name', 'start_date', 'end_date'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::post('/sprints', $args, $user);
    }
}
