<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class DeleteTaskTool extends Tool
{
    public function name(): string { return 'delete_task'; }
    public function description(): string
    {
        return 'Delete a task. Only the reporter or assignee can delete; the API enforces that.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['task_id' => ['type' => 'integer']],
            'required' => ['task_id'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::delete("/tessa/tasks/{$args['task_id']}", [], $user);
    }
}
