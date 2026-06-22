<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class DeleteScriptTool extends Tool
{
    public function name(): string { return 'delete_script'; }
    public function description(): string
    {
        return 'Delete a saved script from your library.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['id' => ['type' => 'integer', 'description' => 'The library item id.']],
            'required' => ['id'],
            'additionalProperties' => false,
        ];
    }
    public function requiredPermission(): ?string
    {
        return 'scripts.generate';
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::delete("/scripts/library/{$args['id']}", [], $user);
    }
}
