<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class VerifyTaskTool extends Tool
{
    public function name(): string { return 'verify_task'; }
    public function description(): string
    {
        return 'As the task reporter, verify a completed task (closes it). For rejecting, use reopen_task.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'task_id' => ['type' => 'integer'],
                'note' => ['type' => 'string'],
            ],
            'required' => ['task_id'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        $taskId = $args['task_id'];
        unset($args['task_id']);
        return ApiSubRequest::post("/tessa/tasks/{$taskId}/verify", $args, $user);
    }
}
