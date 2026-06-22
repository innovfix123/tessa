<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class ListLeaveRequestsTool extends Tool
{
    public function name(): string { return 'list_leave_requests'; }
    public function description(): string
    {
        return 'List YOUR own leave requests, optionally filtered by status and calendar year (defaults to this year). To see your team\'s pending approvals as a manager, use list_team_pending_leaves instead.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'status' => ['type' => 'string', 'enum' => ['pending', 'approved', 'rejected', 'cancelled']],
                'year' => ['type' => 'integer', 'description' => 'Calendar year (e.g. 2026). Defaults to the current year.'],
            ],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        // LeaveController::index only honors status + year and is hardcoded to the
        // signed-in user; user_id/type/date-range were silently ignored before.
        $query = [];
        foreach (['status', 'year'] as $k) {
            if (isset($args[$k])) {
                $query[$k] = $args[$k];
            }
        }
        return ApiSubRequest::get('/leave/requests', $query, $user);
    }
}
