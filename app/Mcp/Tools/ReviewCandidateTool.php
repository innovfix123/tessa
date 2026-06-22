<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class ReviewCandidateTool extends Tool
{
    public function name(): string { return 'review_candidate'; }
    public function description(): string
    {
        return 'Approve or reject a candidate (shortlist decision). A reason is required when rejecting.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'candidate_id' => ['type' => 'integer'],
                'action' => ['type' => 'string', 'enum' => ['approve', 'reject']],
                'reason' => ['type' => 'string', 'description' => 'Required when rejecting.'],
            ],
            'required' => ['candidate_id', 'action'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        $id = $args['candidate_id'];
        $payload = ['action' => $args['action']];
        if (isset($args['reason'])) {
            $payload['reason'] = $args['reason'];
        }
        return ApiSubRequest::post("/hiring/candidates/{$id}/review", $payload, $user);
    }
}
