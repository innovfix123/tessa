<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class DeleteTaskBlockerTool extends Tool
{
    public function name(): string { return 'delete_task_blocker'; }
    public function description(): string
    {
        return 'Remove a manual blocker from a task. Needs the task_id and the blocker_id (from list_task_blockers).';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'task_id' => ['type' => 'integer'],
                'blocker_id' => ['type' => 'integer'],
            ],
            'required' => ['task_id', 'blocker_id'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::delete("/tessa/tasks/{$args['task_id']}/blockers/{$args['blocker_id']}", [], $user);
    }
}
