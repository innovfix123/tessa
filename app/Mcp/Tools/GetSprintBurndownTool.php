<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class GetSprintBurndownTool extends Tool
{
    public function name(): string { return 'get_sprint_burndown'; }
    public function description(): string
    {
        return 'Get a sprint burndown chart data (remaining work over time).';
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
        return ApiSubRequest::get("/sprints/{$args['sprint_id']}/burndown", [], $user);
    }
}
