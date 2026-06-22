<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class GetRevenuePayoutTool extends Tool
{
    public function name(): string { return 'get_revenue_payout'; }
    public function description(): string
    {
        return 'Get the daily revenue/payout figures.';
    }
    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::get('/revenue/daily-payout', [], $user);
    }
}
