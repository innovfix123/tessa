<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class DeleteStoryTool extends Tool
{
    public function name(): string { return 'delete_story'; }
    public function description(): string
    {
        return 'Delete an agile story / backlog item by id.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['story_id' => ['type' => 'integer']],
            'required' => ['story_id'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::delete("/stories/{$args['story_id']}", [], $user);
    }
}
