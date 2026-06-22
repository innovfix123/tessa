<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class EscalateTaskTool extends Tool
{
    public function name(): string { return 'escalate_task'; }
    public function description(): string
    {
        return 'Escalate an OVERDUE task to its reporter / assigner via Slack. Fails (422) if the task is not actually overdue.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'task_id' => ['type' => 'integer'],
            ],
            'required' => ['task_id'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::post("/tessa/tasks/{$args['task_id']}/escalate", [], $user);
    }
}
