<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class CancelLeaveTool extends Tool
{
    public function name(): string { return 'cancel_leave'; }
    public function description(): string
    {
        return 'Cancel a still-PENDING leave request that you created. For an already-APPROVED leave, use request_leave_cancellation (it needs manager approval) instead.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['leave_request_id' => ['type' => 'integer']],
            'required' => ['leave_request_id'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::post("/leave/requests/{$args['leave_request_id']}/cancel", [], $user);
    }
}
