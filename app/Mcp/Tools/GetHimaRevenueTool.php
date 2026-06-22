<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class GetHimaRevenueTool extends Tool
{
    public function name(): string { return 'get_hima_revenue'; }
    public function description(): string
    {
        return 'Get the Hima daily-revenue sheet rows, optionally for a given month (YYYY-MM).';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['month' => ['type' => 'string', 'description' => 'YYYY-MM. Defaults to the latest month.']],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        $query = [];
        if (! empty($args['month'])) {
            $query['month'] = $args['month'];
        }
        return ApiSubRequest::get('/hima-revenue-sheet', $query, $user);
    }
}
