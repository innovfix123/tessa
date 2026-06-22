<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class ListTaskBlockersTool extends Tool
{
    public function name(): string { return 'list_task_blockers'; }
    public function description(): string
    {
        return 'List blockers on a specific task. Returns who-or-what is preventing progress.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['task_id' => ['type' => 'integer']],
            'required' => ['task_id'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::get("/tessa/tasks/{$args['task_id']}/blockers", [], $user);
    }
}
