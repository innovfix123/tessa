<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class GetHrDashboardTool extends Tool
{
    public function name(): string { return 'get_hr_dashboard'; }
    public function description(): string
    {
        return 'Get the HR dashboard — headcount, probation tracker, upcoming confirmations, and related HR metrics.';
    }
    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::get('/hr/dashboard', [], $user);
    }
}
