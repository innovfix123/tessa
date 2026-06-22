<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class CreateTaskFromGmailInsightTool extends Tool
{
    public function name(): string { return 'create_task_from_gmail_insight'; }
    public function description(): string
    {
        return 'Convert a Gmail insight card into a Tessa task, optionally with a priority.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'insight_id' => ['type' => 'integer'],
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
        return ApiSubRequest::post("/gmail/insights/{$id}/create-task", $args, $user);
    }
}
