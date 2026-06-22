<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class RejectRewardTaskTool extends Tool
{
    public function name(): string { return 'reject_reward_task'; }
    public function description(): string
    {
        return 'Reject (forfeit) a submitted reward task with a reason. Reward reviewer only.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'task_id' => ['type' => 'integer'],
                'reason' => ['type' => 'string'],
            ],
            'required' => ['task_id', 'reason'],
            'additionalProperties' => false,
        ];
    }
    public function isAvailableTo(User $user): bool
    {
        return in_array((int) $user->id, array_map('intval', (array) config('rewards.reviewers', [])), true);
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::post("/rewards/tasks/{$args['task_id']}/reject", ['reason' => $args['reason']], $user);
    }
}
