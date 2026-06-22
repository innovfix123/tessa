<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class DeleteBugTool extends Tool
{
    public function name(): string { return 'delete_bug'; }
    public function description(): string
    {
        return 'Delete a reported bug by id.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['bug_id' => ['type' => 'integer']],
            'required' => ['bug_id'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::delete("/bugs/{$args['bug_id']}", [], $user);
    }
}
