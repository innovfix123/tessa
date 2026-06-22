<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class DeleteProjectTool extends Tool
{
    public function name(): string { return 'delete_project'; }
    public function description(): string
    {
        return 'Delete an agile project.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['project_id' => ['type' => 'integer']],
            'required' => ['project_id'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::delete("/projects/{$args['project_id']}", [], $user);
    }
}
