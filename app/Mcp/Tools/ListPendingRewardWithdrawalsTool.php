<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class ListPendingRewardWithdrawalsTool extends Tool
{
    public function name(): string { return 'list_pending_reward_withdrawals'; }
    public function description(): string
    {
        return 'List reward withdrawals awaiting payout. Reward payer only.';
    }
    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
    }
    public function isAvailableTo(User $user): bool
    {
        return in_array((int) $user->id, array_map('intval', (array) config('rewards.payers', [])), true);
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::get('/rewards/withdrawals/pending', [], $user);
    }
}
