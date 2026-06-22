<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;

class AdminMeetingsOverviewTool extends Tool
{
    public function name(): string { return 'admin_meetings_overview'; }
    public function description(): string
    {
        return 'Admin / CEO: cross-team meeting cadence + missing notes. Used by JP to enforce the meeting-notes discipline.';
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
        return ApiSubRequest::get('/admin/meetings-overview', [], $user);
    }
}
