<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class GetPromotionHistoryTool extends Tool
{
    public function name(): string { return 'get_promotion_history'; }
    public function description(): string
    {
        return 'Get an employee\'s promotion / designation-change history.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['user_id' => ['type' => 'integer']],
            'required' => ['user_id'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::get("/employees/{$args['user_id']}/promotion-history", [], $user);
    }
}
