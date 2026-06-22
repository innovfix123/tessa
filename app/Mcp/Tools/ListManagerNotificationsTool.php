<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class ListManagerNotificationsTool extends Tool
{
    public function name(): string { return 'list_manager_notifications'; }
    public function description(): string
    {
        return 'List your dashboard "Team updates" feed (manager-notification items about your reports). Use clear_notifications to clear it.';
    }
    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::get('/manager-notifications', [], $user);
    }
}
