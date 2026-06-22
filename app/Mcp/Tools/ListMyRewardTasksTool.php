<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class ListMyRewardTasksTool extends Tool
{
    public function name(): string { return 'list_my_reward_tasks'; }
    public function description(): string
    {
        return 'List reward tasks assigned to the signed-in user (status, coin reward, deadline).';
    }
    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::get('/rewards/tasks/mine', [], $user);
    }
}
