<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class UpdateTaskTool extends Tool
{
    public function name(): string { return 'update_task'; }
    public function description(): string
    {
        return 'Update an existing task — change status (pending/in_progress/completed/cancelled/on_hold), title, description, deadline, priority, or add a status note. To CLOSE a task, use verify_task instead.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'task_id' => ['type' => 'integer'],
                'status' => ['type' => 'string', 'enum' => ['pending', 'in_progress', 'completed', 'cancelled', 'on_hold']],
                'title' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'priority' => ['type' => 'string', 'enum' => ['low', 'medium', 'high', 'urgent']],
                'deadline' => ['type' => 'string'],
                'status_note' => ['type' => 'string'],
            ],
            'required' => ['task_id'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        $taskId = $args['task_id'];
        unset($args['task_id']);
        return ApiSubRequest::put("/tessa/tasks/{$taskId}", $args, $user);
    }
}
