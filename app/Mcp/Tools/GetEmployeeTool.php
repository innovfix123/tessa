<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Mcp\ToolException;
use App\Models\User;
use Illuminate\Http\Request;

class GetEmployeeTool extends Tool
{
    public function name(): string { return 'get_employee'; }

    public function description(): string
    {
        return 'Fetch ONE employee\'s full Tessa profile and current employment status by user_id or name. '
            .'Returns personal & contact details, reporting manager, department, designation, employment type, '
            .'employee_status (active / probation / intern / notice / resigned / terminated), probation & '
            .'confirmation dates, intern-conversion status, documents, and (for finance roles) salary. HR/finance '
            .'only. Pass user_id when you have it; otherwise pass a name in search and use list_employees to '
            .'disambiguate.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'user_id' => ['type' => 'integer', 'description' => 'The employee\'s Tessa user id (preferred — exact).'],
                'search' => ['type' => 'string', 'description' => 'Employee name, full or partial — used when you don\'t have the user_id.'],
            ],
            'additionalProperties' => false,
        ];
    }

    // Single full-profile lookup is sensitive (addresses, documents, salary), so
    // it stays HR/finance-only via the 'employees' feature gate in FEATURE_MAP —
    // the same audience that gets the rich directory from list_employees.
    public function handle(array $args, User $user, Request $request): mixed
    {
        $userId = isset($args['user_id']) ? (int) $args['user_id'] : 0;
        $search = isset($args['search']) ? trim((string) $args['search']) : '';
        if ($userId <= 0 && $search === '') {
            throw new ToolException('Provide either user_id or search (employee name).', 400);
        }

        // Reuse the role-gated /employees endpoint — identical data to the Team
        // tab. show_all=true so resigned/terminated staff are also resolvable.
        $query = ['show_all' => true];
        if ($userId <= 0) {
            $query['search'] = $search;
        }
        $payload = ApiSubRequest::get('/employees', $query, $user);
        $employees = $payload['employees'] ?? [];

        $match = null;
        if ($userId > 0) {
            foreach ($employees as $e) {
                if ((int) ($e['id'] ?? 0) === $userId) { $match = $e; break; }
            }
        } else {
            // Prefer an exact (case-insensitive) name; otherwise, if the search
            // narrowed to exactly one person, take that one.
            foreach ($employees as $e) {
                if (strcasecmp(trim((string) ($e['name'] ?? '')), $search) === 0) { $match = $e; break; }
            }
            if (! $match && count($employees) === 1) {
                $match = $employees[0];
            }
            if (! $match && count($employees) > 1) {
                // Ambiguous name — hand back the shortlist so the caller can pick.
                return [
                    'matched' => false,
                    'message' => 'Multiple employees match "'.$search.'". Pass user_id to pick one.',
                    'candidates' => array_map(static fn ($e) => [
                        'id' => $e['id'] ?? null,
                        'name' => $e['name'] ?? null,
                        'designation' => $e['designation'] ?? null,
                        'employee_status' => $e['employee_status'] ?? null,
                    ], array_values($employees)),
                ];
            }
        }

        if (! $match) {
            throw new ToolException(
                'No employee found '.($userId > 0 ? "with id {$userId}" : "matching \"{$search}\"").'.',
                404,
            );
        }

        return [
            'matched' => true,
            'can_see_salary' => $payload['can_see_salary'] ?? false,
            'employee' => $match,
        ];
    }
}
