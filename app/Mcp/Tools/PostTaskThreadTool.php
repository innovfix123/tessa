<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class PostTaskThreadTool extends Tool
{
    public function name(): string { return 'post_task_thread'; }
    public function description(): string
    {
        return 'Post a message to a task\'s discussion thread. You must be the assigner, assignee, or a participant. Tessa may auto-update the task (status / blocker) based on your message.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'task_id' => ['type' => 'integer'],
                'content' => ['type' => 'string', 'description' => 'Message body (non-empty).'],
            ],
            'required' => ['task_id', 'content'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::post(
            "/tessa/tasks/{$args['task_id']}/thread",
            ['content' => $args['content']],
            $user,
        );
    }
}
