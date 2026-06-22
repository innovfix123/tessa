<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class RequestLeaveCancellationTool extends Tool
{
    public function name(): string { return 'request_leave_cancellation'; }
    public function description(): string
    {
        return 'Request to cancel one of your already-APPROVED leave requests (it goes to your manager to approve). For still-pending leave use cancel_leave instead. Notifies your manager on Slack.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'leave_request_id' => ['type' => 'integer'],
                'reason' => ['type' => 'string', 'description' => 'Optional reason (max 1000 chars).'],
            ],
            'required' => ['leave_request_id'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        $body = [];
        if (! empty($args['reason'])) {
            $body['reason'] = $args['reason'];
        }
        return ApiSubRequest::post("/leave/requests/{$args['leave_request_id']}/request-cancellation", $body, $user);
    }
}
