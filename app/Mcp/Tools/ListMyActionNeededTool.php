<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class ListMyActionNeededTool extends Tool
{
    public function name(): string { return 'list_my_action_needed'; }
    public function description(): string
    {
        return 'List tasks where the signed-in user owes a check-in, verification, or response. The "do-this-next" inbox.';
    }
    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::get('/tessa/tasks/my-action-needed', [], $user);
    }
}
