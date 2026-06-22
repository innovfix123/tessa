<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class CloseSprintTool extends Tool
{
    public function name(): string { return 'close_sprint'; }
    public function description(): string
    {
        return 'Close a sprint and record its velocity. The sprint creator can close their own sprint; a configured override user can close any sprint.';
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
        return ApiSubRequest::post("/sprints/{$args['sprint_id']}/close", [], $user);
    }
}
