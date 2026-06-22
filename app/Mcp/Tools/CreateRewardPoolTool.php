<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class CreateRewardPoolTool extends Tool
{
    public function name(): string { return 'create_reward_pool'; }
    public function description(): string
    {
        return 'Log a team-reward pool (a lump reward for your team, settled by the payer). Reward pool creator only.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'amount' => ['type' => 'number'],
            ],
            'required' => ['title', 'amount'],
            'additionalProperties' => false,
        ];
    }
    public function isAvailableTo(User $user): bool
    {
        return in_array((int) $user->id, array_map('intval', (array) config('rewards.pool_creators', [])), true);
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::post('/rewards/pools', $args, $user);
    }
}
