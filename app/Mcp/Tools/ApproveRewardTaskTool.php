<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class ApproveRewardTaskTool extends Tool
{
    public function name(): string { return 'approve_reward_task'; }
    public function description(): string
    {
        return 'Approve a submitted reward task, optionally at a reduced final amount. Reward reviewer only. Approving auto-creates the pending withdrawal.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'task_id' => ['type' => 'integer'],
                'final_amount' => ['type' => 'number', 'description' => 'Reduced amount; omit to approve the full amount.'],
                'note' => ['type' => 'string'],
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
        return ApiSubRequest::post("/rewards/tasks/{$id}/approve", $args, $user);
    }
}
