<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class SaveScriptToLibraryTool extends Tool
{
    public function name(): string { return 'save_script_to_library'; }
    public function description(): string
    {
        return 'Save an ad script to your library.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'body' => ['type' => 'string', 'description' => 'The script text.'],
                'language' => ['type' => 'string'],
                'category' => ['type' => 'string'],
                'script_generation_id' => ['type' => 'integer', 'description' => 'Optional: the generation this came from.'],
            ],
            'required' => ['body', 'language', 'category'],
            'additionalProperties' => false,
        ];
    }
    public function requiredPermission(): ?string
    {
        return 'scripts.generate';
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::post('/scripts/library', $args, $user);
    }
}
