<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class DeleteEpicTool extends Tool
{
    public function name(): string { return 'delete_epic'; }
    public function description(): string
    {
        return 'Delete an epic.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['epic_id' => ['type' => 'integer']],
            'required' => ['epic_id'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::delete("/epics/{$args['epic_id']}", [], $user);
    }
}
