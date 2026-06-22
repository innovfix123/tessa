<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class ListScriptsTool extends Tool
{
    public function name(): string { return 'list_scripts'; }
    public function description(): string
    {
        return 'List your saved ad-script library items.';
    }
    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
    }
    public function requiredPermission(): ?string
    {
        return 'scripts.generate';
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::get('/scripts', [], $user);
    }
}
