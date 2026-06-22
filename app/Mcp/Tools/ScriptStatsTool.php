<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class ScriptStatsTool extends Tool
{
    public function name(): string { return 'script_stats'; }
    public function description(): string
    {
        return 'Get script-generation usage statistics (CEO view).';
    }
    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
    }
    public function allowedRoleSlugs(): ?array
    {
        return ['ceo'];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::get('/scripts/stats', [], $user);
    }
}
