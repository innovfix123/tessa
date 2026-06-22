<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class GetAiUsageTool extends Tool
{
    public function name(): string { return 'get_ai_usage'; }
    public function description(): string
    {
        return 'Get the OpenRouter AI usage / spend summary across Tessa AI features (CEO only).';
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
        return ApiSubRequest::get('/ai-usage', [], $user);
    }
}
