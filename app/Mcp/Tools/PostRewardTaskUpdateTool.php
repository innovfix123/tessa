<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class PostRewardTaskUpdateTool extends Tool
{
    public function name(): string { return 'post_reward_task_update'; }
    public function description(): string
    {
        return 'As the assignee of a reward task, post a progress update (and optionally a link to evidence).';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'task_id' => ['type' => 'integer'],
                'note' => ['type' => 'string', 'description' => 'The progress update text.'],
                'evidence_url' => ['type' => 'string', 'description' => 'Optional link to supporting evidence.'],
            ],
            'required' => ['task_id', 'note'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        $payload = ['note' => $args['note']];
        if (isset($args['evidence_url'])) {
            $payload['evidence_url'] = $args['evidence_url'];
        }
        return ApiSubRequest::post("/rewards/tasks/{$args['task_id']}/updates", $payload, $user);
    }
}
