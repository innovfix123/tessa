<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class GetSprintCapacityTool extends Tool
{
    public function name(): string { return 'get_sprint_capacity'; }
    public function description(): string
    {
        return 'Get a sprint capacity breakdown (committed vs available hours per assignee).';
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
        return ApiSubRequest::get("/sprints/{$args['sprint_id']}/capacity", [], $user);
    }
}
