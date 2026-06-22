<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class ReviewLeaveTool extends Tool
{
    public function name(): string { return 'review_leave'; }
    public function description(): string
    {
        return 'As the reporting manager, approve or reject a pending leave request.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'leave_request_id' => ['type' => 'integer'],
                'decision' => ['type' => 'string', 'enum' => ['approve', 'reject']],
                'note' => ['type' => 'string'],
            ],
            'required' => ['leave_request_id', 'decision'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        $id = $args['leave_request_id'];
        // Backend (LeaveController::review) validates 'action' (approve|reject);
        // keep 'decision' as the friendly tool input and map it here.
        $payload = [
            'action' => $args['decision'],
            'note' => $args['note'] ?? null,
        ];
        return ApiSubRequest::post("/leave/requests/{$id}/review", $payload, $user);
    }
}
