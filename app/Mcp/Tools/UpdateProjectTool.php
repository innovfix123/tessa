<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class UpdateProjectTool extends Tool
{
    public function name(): string { return 'update_project'; }
    public function description(): string
    {
        return 'Rename an agile project (name must be unique).';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'project_id' => ['type' => 'integer'],
                'name' => ['type' => 'string'],
            ],
            'required' => ['project_id', 'name'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::put("/projects/{$args['project_id']}", ['name' => $args['name']], $user);
    }
}
