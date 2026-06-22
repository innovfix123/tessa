<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class GetTaskThreadTool extends Tool
{
    public function name(): string { return 'get_task_thread'; }
    public function description(): string
    {
        return 'Read a task\'s discussion thread (messages + participants). You must be the assigner, assignee, or a thread participant. Marks the thread read for you.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'task_id' => ['type' => 'integer'],
            ],
            'required' => ['task_id'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::get("/tessa/tasks/{$args['task_id']}/thread", [], $user);
    }
}
