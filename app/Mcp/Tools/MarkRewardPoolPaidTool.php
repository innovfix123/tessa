<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class MarkRewardPoolPaidTool extends Tool
{
    public function name(): string { return 'mark_reward_pool_paid'; }
    public function description(): string
    {
        return 'Settle a team-reward pool (mark it paid, optionally with a UTR number and note). Reward payer only.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'pool_id' => ['type' => 'integer'],
                'utr_number' => ['type' => 'string'],
                'note' => ['type' => 'string'],
            ],
            'required' => ['pool_id'],
            'additionalProperties' => false,
        ];
    }
    public function isAvailableTo(User $user): bool
    {
        return in_array((int) $user->id, array_map('intval', (array) config('rewards.payers', [])), true);
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        $id = $args['pool_id'];
        unset($args['pool_id']);
        return ApiSubRequest::post("/rewards/pools/{$id}/mark-paid", $args, $user);
    }
}
