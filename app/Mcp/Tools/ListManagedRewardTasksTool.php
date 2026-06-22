<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class ListManagedRewardTasksTool extends Tool
{
    public function name(): string { return 'list_managed_reward_tasks'; }
    public function description(): string
    {
        return 'List all reward tasks across the company (submitted, assigned, approved, rejected) for review. Reward reviewer only.';
    }
    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
    }
    public function isAvailableTo(User $user): bool
    {
        return in_array((int) $user->id, array_map('intval', (array) config('rewards.reviewers', [])), true);
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::get('/rewards/tasks/manage/all', [], $user);
    }
}
