<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class InviteToTaskTool extends Tool
{
    public function name(): string { return 'invite_to_task'; }
    public function description(): string
    {
        return 'Invite another user into a task\'s discussion thread (adds them as a participant). You must be the assigner, assignee, or a participant.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'task_id' => ['type' => 'integer'],
                'user_id' => ['type' => 'integer', 'description' => 'User to invite.'],
            ],
            'required' => ['task_id', 'user_id'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::post(
            "/tessa/tasks/{$args['task_id']}/invite",
            ['user_id' => $args['user_id']],
            $user,
        );
    }
}
