<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class GetMyRewardWalletTool extends Tool
{
    public function name(): string { return 'get_my_reward_wallet'; }
    public function description(): string
    {
        return 'Fetch the signed-in user\'s reward wallet (balance, lifetime earnings, withdrawal history).';
    }
    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::get('/rewards/wallet', [], $user);
    }
}
