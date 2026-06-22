<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use App\Services\UserFeatureService;
use Illuminate\Http\Request;

class ListEmployeesTool extends Tool
{
    public function name(): string { return 'list_employees'; }
    public function description(): string
    {
        return 'List Tessa employees. HR/finance roles get the full Team directory — each person\'s details and '
            .'current employment status (active / probation / intern / notice / resigned / terminated), reporting '
            .'manager, department, designation, documents and more. Everyone else gets a lightweight '
            .'id+name+designation roster for resolving a colleague\'s user_id (e.g. before create_task, '
            .'invite_to_task, request_leave). Filter with search (name), department_id, or — HR/finance — '
            .'employee_status / show_all. For one person\'s full profile use get_employee.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'search' => ['type' => 'string', 'description' => 'Substring match on name.'],
                'department_id' => ['type' => 'integer', 'description' => 'Filter to one department by id.'],
                'employee_status' => ['type' => 'string', 'description' => 'HR/finance only. Filter by status: active, probation, intern, notice, resigned, terminated, or freelancer.'],
                'show_all' => ['type' => 'boolean', 'description' => 'HR/finance only. Include inactive/exited employees (default false = active only).'],
            ],
            'additionalProperties' => false,
        ];
    }
    // Available to every employee with the 'tasks' feature (all staff except the
    // hiring-only freelance_recruiter portal) — see FEATURE_MAP. HR/finance (the
    // 'employees' feature) get the rich /employees payload; everyone else gets a
    // lightweight id+name+designation roster so they can resolve a colleague's
    // user_id for create_task/invite_to_task/request_leave.
    public function handle(array $args, User $user, Request $request): mixed
    {
        // HR/finance see the full directory (salary, documents, …) via the
        // role-gated /employees endpoint, unchanged.
        if (in_array('employees', UserFeatureService::featuresFor($user), true)) {
            return ApiSubRequest::get('/employees', $args, $user);
        }

        // Everyone else gets a non-sensitive roster — mirrors the already-public
        // assignee pickers (ChecklistController/TicketController::assignees).
        $query = User::where('is_active', true);
        if (! empty($args['search'])) {
            $query->where('name', 'like', '%'.$args['search'].'%');
        }

        return $query->orderBy('name')
            ->get(['id', 'name', 'designation'])
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'designation' => $u->designation,
            ])
            ->values();
    }
}
