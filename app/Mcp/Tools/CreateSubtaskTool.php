<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class CreateSubtaskTool extends Tool
{
    public function name(): string { return 'create_subtask'; }
    public function description(): string
    {
        return 'Add a subtask (checklist item) to an existing task. Useful for breaking large tasks down.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'task_id' => ['type' => 'integer'],
                'title' => ['type' => 'string'],
            ],
            'required' => ['task_id', 'title'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        $taskId = $args['task_id'];
        return ApiSubRequest::post("/tessa/tasks/{$taskId}/subtasks", ['title' => $args['title']], $user);
    }
}
