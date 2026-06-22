<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class CreateTaskBlockerTool extends Tool
{
    public function name(): string { return 'create_task_blocker'; }
    public function description(): string
    {
        return 'Record a blocker on a task (assignee-only). Surfaces it in the reporter\'s blocker inbox.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'task_id' => ['type' => 'integer'],
                'note' => ['type' => 'string', 'description' => 'What is blocking this task?'],
            ],
            'required' => ['task_id', 'note'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        $taskId = $args['task_id'];
        unset($args['task_id']);
        return ApiSubRequest::post("/tessa/tasks/{$taskId}/blockers", $args, $user);
    }
}
