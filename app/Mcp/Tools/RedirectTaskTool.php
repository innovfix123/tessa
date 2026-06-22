<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class RedirectTaskTool extends Tool
{
    public function name(): string { return 'redirect_task'; }
    public function description(): string
    {
        return 'Reassign a task to a different person. You must be its current assignee or shared assigner. Notifies the new assignee, the old assignee, and the creator on Slack.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'task_id' => ['type' => 'integer'],
                'assigned_to' => ['type' => 'integer', 'description' => 'New assignee user id.'],
                'deadline' => ['type' => 'string', 'description' => 'Optional new deadline (ISO date or datetime).'],
            ],
            'required' => ['task_id', 'assigned_to'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        $body = ['assigned_to' => $args['assigned_to']];
        if (! empty($args['deadline'])) {
            $body['deadline'] = $args['deadline'];
        }
        return ApiSubRequest::post("/tessa/tasks/{$args['task_id']}/redirect", $body, $user);
    }
}
