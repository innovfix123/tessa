<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class ListPendingWorkTool extends Tool
{
    public function name(): string { return 'list_pending_work'; }
    public function description(): string
    {
        return 'List the signed-in user\'s pending work across tasks + action items + check-ins. The "what is on my plate" inbox.';
    }
    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::get('/pending-work', [], $user);
    }
}
