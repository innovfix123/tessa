<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class ListMyRewardWithdrawalsTool extends Tool
{
    public function name(): string { return 'list_my_reward_withdrawals'; }
    public function description(): string
    {
        return 'List your own reward withdrawals and their payout status (pending / paid, amount, UTR).';
    }
    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::get('/rewards/withdrawals/me', [], $user);
    }
}
