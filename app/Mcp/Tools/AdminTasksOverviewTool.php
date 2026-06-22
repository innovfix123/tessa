<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;

class AdminTasksOverviewTool extends Tool
{
    public function name(): string { return 'admin_tasks_overview'; }
    public function description(): string
    {
        return 'Admin / CEO: company-wide task health (per-user counts of open, overdue, blocked tasks).';
    }
    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
    }
    public function allowedRoleSlugs(): ?array
    {
        return [Role::SLUG_ADMIN, Role::SLUG_CEO];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::get('/admin/tasks-overview', [], $user);
    }
}
