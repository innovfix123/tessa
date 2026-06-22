<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class UpdateRewardTaskTool extends Tool
{
    public function name(): string { return 'update_reward_task'; }
    public function description(): string
    {
        return 'Edit a reward task (title, description, amount, deadline). Reward reviewer only.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'task_id' => ['type' => 'integer'],
                'title' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'amount' => ['type' => 'number'],
                'deadline' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
            ],
            'required' => ['task_id'],
            'additionalProperties' => false,
        ];
    }
    public function isAvailableTo(User $user): bool
    {
        return in_array((int) $user->id, array_map('intval', (array) config('rewards.reviewers', [])), true);
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        $id = $args['task_id'];
        unset($args['task_id']);
        return ApiSubRequest::patch("/rewards/tasks/{$id}", $args, $user);
    }
}
