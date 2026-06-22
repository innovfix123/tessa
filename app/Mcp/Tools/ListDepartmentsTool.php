<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;

class ListDepartmentsTool extends Tool
{
    public function name(): string { return 'list_departments'; }
    public function description(): string
    {
        return 'List all departments in Tessa.';
    }
    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
    }

    // Matches DepartmentController::ALLOWED_ROLES (HR + leadership).
    public function allowedRoleSlugs(): ?array
    {
        return [Role::SLUG_CEO, Role::SLUG_COO, Role::SLUG_CFO, Role::SLUG_HR, Role::SLUG_HR_OPERATIONS, Role::SLUG_BUSINESS_ANALYST];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::get('/departments', [], $user);
    }
}
