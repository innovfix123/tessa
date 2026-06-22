<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class CreateTaskFromSlackInsightTool extends Tool
{
    public function name(): string { return 'create_task_from_slack_insight'; }
    public function description(): string
    {
        return 'Convert a Slack insight card into a Tessa task, optionally assigning it and setting a priority.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'insight_id' => ['type' => 'integer'],
                'assigned_to' => ['type' => 'integer', 'description' => 'User id to assign the task to (defaults to you).'],
                'priority' => ['type' => 'string', 'enum' => ['low', 'medium', 'high', 'urgent']],
            ],
            'required' => ['insight_id'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        $id = $args['insight_id'];
        unset($args['insight_id']);
        return ApiSubRequest::post("/slack/insights/{$id}/create-task", $args, $user);
    }
}
