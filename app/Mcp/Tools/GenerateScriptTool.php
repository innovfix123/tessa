<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class GenerateScriptTool extends Tool
{
    public function name(): string { return 'generate_script'; }
    public function description(): string
    {
        return 'AI-generate ad scripts for a language + category (1-10 at a time), optionally guided by a creative brief.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'language' => ['type' => 'string', 'description' => 'A supported language (the API validates; use the portal to see options).'],
                'category' => ['type' => 'string', 'description' => 'A supported category (the API validates).'],
                'creative_brief' => ['type' => 'string'],
                'count' => ['type' => 'integer', 'description' => '1-10'],
            ],
            'required' => ['language', 'category', 'count'],
            'additionalProperties' => false,
        ];
    }
    public function requiredPermission(): ?string
    {
        return 'scripts.generate';
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::post('/scripts/generate', $args, $user);
    }
}
