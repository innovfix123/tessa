<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class ListMyRewardPoolsTool extends Tool
{
    public function name(): string { return 'list_my_reward_pools'; }
    public function description(): string
    {
        return 'List the team-reward pools you have logged. Reward pool creator only.';
    }
    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
    }
    public function isAvailableTo(User $user): bool
    {
        return in_array((int) $user->id, array_map('intval', (array) config('rewards.pool_creators', [])), true);
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::get('/rewards/pools/mine', [], $user);
    }
}
