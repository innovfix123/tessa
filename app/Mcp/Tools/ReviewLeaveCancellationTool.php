<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class ReviewLeaveCancellationTool extends Tool
{
    public function name(): string { return 'review_leave_cancellation'; }
    public function description(): string
    {
        return 'As the manager (or JP for manager-less staff), approve or reject an employee\'s request to cancel an approved leave. action=approve cancels the leave; action=reject keeps it. Notifies the employee on Slack.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'leave_request_id' => ['type' => 'integer'],
                'action' => ['type' => 'string', 'enum' => ['approve', 'reject']],
                'note' => ['type' => 'string', 'description' => 'Optional note (max 500 chars).'],
            ],
            'required' => ['leave_request_id', 'action'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        $body = ['action' => $args['action']];
        if (! empty($args['note'])) {
            $body['note'] = $args['note'];
        }
        return ApiSubRequest::post("/leave/requests/{$args['leave_request_id']}/review-cancellation", $body, $user);
    }
}
