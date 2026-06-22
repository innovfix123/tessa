<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class ActivateSprintTool extends Tool
{
    public function name(): string { return 'activate_sprint'; }
    public function description(): string
    {
        return 'Start a planned sprint (move it to active). Only the sprint creator can activate it.';
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
        return ApiSubRequest::post("/sprints/{$args['sprint_id']}/activate", [], $user);
    }
}
