<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class ReopenTaskTool extends Tool
{
    public function name(): string { return 'reopen_task'; }
    public function description(): string
    {
        return 'As the task reporter, reopen a completed task (push it back to the assignee).';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'task_id' => ['type' => 'integer'],
                'reason' => ['type' => 'string', 'description' => 'Why the task is being reopened (required).'],
            ],
            'required' => ['task_id', 'reason'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        $taskId = $args['task_id'];
        unset($args['task_id']);
        return ApiSubRequest::post("/tessa/tasks/{$taskId}/reopen", $args, $user);
    }
}
