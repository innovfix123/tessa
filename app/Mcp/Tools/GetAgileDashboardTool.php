<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class GetAgileDashboardTool extends Tool
{
    public function name(): string { return 'get_agile_dashboard'; }
    public function description(): string
    {
        return 'Get the agile dashboard rollup — active sprints, story/bug counts, and overall progress.';
    }
    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::get('/agile/dashboard', [], $user);
    }
}
