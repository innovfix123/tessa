<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class ListTessaChatsTool extends Tool
{
    public function name(): string { return 'list_tessa_chats'; }
    public function description(): string
    {
        return 'List your saved Tessa AI assistant chat threads.';
    }
    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::get('/tessa/chats', [], $user);
    }
}
