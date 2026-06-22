<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class DeleteSubtaskTool extends Tool
{
    public function name(): string { return 'delete_subtask'; }
    public function description(): string
    {
        return 'Delete a subtask from a task. Needs the parent task_id and the subtask_id (from get_task).';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'task_id' => ['type' => 'integer'],
                'subtask_id' => ['type' => 'integer'],
            ],
            'required' => ['task_id', 'subtask_id'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::delete("/tessa/tasks/{$args['task_id']}/subtasks/{$args['subtask_id']}", [], $user);
    }
}
