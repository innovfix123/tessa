<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class GetRewardTaskTool extends Tool
{
    public function name(): string { return 'get_reward_task'; }
    public function description(): string
    {
        return 'Fetch one reward task by id, including its progress updates and assignee. Visible to the reward reviewer (JP) or the task\'s assignee.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['task_id' => ['type' => 'integer']],
            'required' => ['task_id'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::get("/rewards/tasks/{$args['task_id']}", [], $user);
    }
}
