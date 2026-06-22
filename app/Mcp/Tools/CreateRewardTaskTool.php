<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class CreateRewardTaskTool extends Tool
{
    public function name(): string { return 'create_reward_task'; }
    public function description(): string
    {
        return 'Assign a reward task to an employee (a coin/cash reward they earn on completion). Reward reviewer only.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'assigned_to_id' => ['type' => 'integer'],
                'title' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'amount' => ['type' => 'number', 'description' => 'Reward amount.'],
                'deadline' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
            ],
            'required' => ['assigned_to_id', 'title', 'amount'],
            'additionalProperties' => false,
        ];
    }
    public function isAvailableTo(User $user): bool
    {
        return in_array((int) $user->id, array_map('intval', (array) config('rewards.reviewers', [])), true);
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::post('/rewards/tasks', $args, $user);
    }
}
