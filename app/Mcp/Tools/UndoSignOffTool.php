<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class UndoSignOffTool extends Tool
{
    public function name(): string { return 'undo_sign_off'; }
    public function description(): string
    {
        return 'Undo today\'s end-of-day sign-off (re-opens the day).';
    }
    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::delete('/signoff', [], $user);
    }
}
