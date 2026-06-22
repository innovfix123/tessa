<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class UndoSignInTool extends Tool
{
    public function name(): string { return 'undo_sign_in'; }
    public function description(): string
    {
        return 'Undo today\'s attendance sign-in. Only works within 5 minutes of signing in; otherwise it is a no-op.';
    }
    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::delete('/signin', [], $user);
    }
}
