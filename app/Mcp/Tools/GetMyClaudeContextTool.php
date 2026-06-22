<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class GetMyClaudeContextTool extends Tool
{
    public function name(): string { return 'get_my_claude_context'; }

    public function description(): string
    {
        return "Get the signed-in user's recent daily Claude-context summaries (most recent first). "
            .'Use it to check whether today\'s context is already recorded before logging a new one.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
    }

    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::get('/claude-context', [], $user);
    }
}
