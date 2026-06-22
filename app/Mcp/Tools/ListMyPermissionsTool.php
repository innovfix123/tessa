<?php

namespace App\Mcp\Tools;

use App\Mcp\McpToolRegistry;
use App\Mcp\Tool;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;

class ListMyPermissionsTool extends Tool
{
    public function name(): string { return 'list_my_permissions'; }
    public function description(): string
    {
        return 'Return the signed-in user\'s role, the feature permissions attached to that role, and the list of MCP tool names they are allowed to call. Useful for the model to plan ahead.';
    }
    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        $role = Role::where('slug', $user->role)->first();
        $permissions = $role
            ? Permission::where('role_id', $role->id)->pluck('permission')->toArray()
            : [];

        $registry = app(McpToolRegistry::class);
        $tools = array_column($registry->toolsForUser($user), 'name');

        return [
            'role' => $user->role,
            'role_label' => $role?->name,
            'permissions' => $permissions,
            'tool_names' => $tools,
            'tool_count' => count($tools),
        ];
    }
}
