<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class HimaRevenueMonthsTool extends Tool
{
    public function name(): string { return 'hima_revenue_months'; }
    public function description(): string
    {
        return 'List the months available in the Hima daily-revenue sheet.';
    }
    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::get('/hima-revenue-sheet/months', [], $user);
    }
}
