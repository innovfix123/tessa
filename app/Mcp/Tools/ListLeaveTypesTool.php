<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class ListLeaveTypesTool extends Tool
{
    public function name(): string { return 'list_leave_types'; }
    public function description(): string
    {
        return 'List the leave types the signed-in user can apply for (active types, filtered by gender). '
            .'Returns each type\'s slug, name, whether it needs manager approval, and whether it is hourly. '
            .'Use the slug with request_leave.';
    }
    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::get('/leave/types', [], $user);
    }
}
