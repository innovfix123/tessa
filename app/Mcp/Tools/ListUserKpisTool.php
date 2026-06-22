<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ListUserKpisTool extends Tool
{
    public function name(): string { return 'list_user_kpis'; }
    public function description(): string
    {
        return 'List KPI entries + targets for one week. Pass user_id for a specific person (yourself or a subordinate), or omit it to get everyone you can see. The API enforces the access boundary.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'user_id' => ['type' => 'integer', 'description' => 'A specific user. Omit to list every user you are allowed to see for the week.'],
                'week_key' => ['type' => 'string', 'description' => 'Monday of the week, YYYY-MM-DD. Defaults to the current week.'],
            ],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        // KpiController::index requires a single week_key (YYYY-MM-DD), not a range.
        $query = [
            'week_key' => $args['week_key']
                ?? Carbon::now('Asia/Kolkata')->startOfWeek(Carbon::MONDAY)->format('Y-m-d'),
        ];
        if (isset($args['user_id'])) {
            $query['user_id'] = $args['user_id'];
        }
        return ApiSubRequest::get('/kpi', $query, $user);
    }
}
