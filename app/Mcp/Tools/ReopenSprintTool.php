<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class ReopenSprintTool extends Tool
{
    public function name(): string { return 'reopen_sprint'; }
    public function description(): string
    {
        return 'Reopen a closed or in-review sprint back to active. Only the sprint creator can do this.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['sprint_id' => ['type' => 'integer']],
            'required' => ['sprint_id'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::post("/sprints/{$args['sprint_id']}/reopen", [], $user);
    }
}
