<?php

namespace App\Mcp\Tools;

use App\Mcp\Tool;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;

class WhoamiTool extends Tool
{
    public function name(): string { return 'whoami'; }
    public function description(): string
    {
        return 'Return the signed-in Tessa user (id, name, email, role, role label) plus today\'s date (IST). Always call this first to resolve the current user and the current date.';
    }
    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'role_label' => Role::where('slug', $user->role)->value('name'),
            'reporting_manager_id' => $user->reporting_manager_id,
            'today' => \Carbon\Carbon::today('Asia/Kolkata')->toDateString(),
        ];
    }
}
