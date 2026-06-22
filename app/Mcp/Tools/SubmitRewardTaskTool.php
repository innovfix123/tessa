<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class SubmitRewardTaskTool extends Tool
{
    public function name(): string { return 'submit_reward_task'; }
    public function description(): string
    {
        return 'As the assignee, submit a reward task for the reviewer (JP) to approve. Optionally include a closing note and an evidence link.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'task_id' => ['type' => 'integer'],
                'note' => ['type' => 'string', 'description' => 'Optional submission note.'],
                'evidence_url' => ['type' => 'string', 'description' => 'Optional link to supporting evidence.'],
            ],
            'required' => ['task_id'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        $payload = [];
        if (isset($args['note'])) {
            $payload['note'] = $args['note'];
        }
        if (isset($args['evidence_url'])) {
            $payload['evidence_url'] = $args['evidence_url'];
        }
        return ApiSubRequest::post("/rewards/tasks/{$args['task_id']}/submit", $payload, $user);
    }
}
