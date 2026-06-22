<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class ExtendTaskDeadlineTool extends Tool
{
    public function name(): string { return 'extend_task_deadline'; }
    public function description(): string
    {
        return 'Request a deadline extension on a task assigned to you. The first extension is free; subsequent ones need reporter approval (approve_task_extension).';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'task_id' => ['type' => 'integer'],
                'days' => ['type' => 'integer', 'enum' => [1, 2], 'description' => 'Days to extend by (1 or 2). Anchored to now if the task is already overdue.'],
            ],
            'required' => ['task_id', 'days'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        $taskId = $args['task_id'];
        unset($args['task_id']);
        return ApiSubRequest::post("/tessa/tasks/{$taskId}/extend-deadline", $args, $user);
    }
}
