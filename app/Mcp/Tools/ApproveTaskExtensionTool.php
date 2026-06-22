<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class ApproveTaskExtensionTool extends Tool
{
    public function name(): string { return 'approve_task_extension'; }
    public function description(): string
    {
        return 'As the task reporter, approve or deny a pending deadline-extension request.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'task_id' => ['type' => 'integer'],
                'decision' => ['type' => 'string', 'enum' => ['approve', 'deny']],
                'note' => ['type' => 'string'],
            ],
            'required' => ['task_id', 'decision'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        $taskId = $args['task_id'];
        $endpoint = $args['decision'] === 'approve' ? 'approve-extension' : 'deny-extension';
        $payload = isset($args['note']) ? ['note' => $args['note']] : [];
        return ApiSubRequest::post("/tessa/tasks/{$taskId}/{$endpoint}", $payload, $user);
    }
}
