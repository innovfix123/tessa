<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class NudgeTaskTool extends Tool
{
    public function name(): string { return 'nudge_task'; }
    public function description(): string
    {
        return 'Send a Slack nudge to a task\'s assignee, reminding them about it.';
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
        return ApiSubRequest::post("/tessa/tasks/{$args['task_id']}/nudge", [], $user);
    }
}
