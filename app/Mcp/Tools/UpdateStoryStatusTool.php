<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class UpdateStoryStatusTool extends Tool
{
    public function name(): string { return 'update_story_status'; }
    public function description(): string
    {
        return 'Move a story between board lanes (e.g. todo → in_progress → done).';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'story_id' => ['type' => 'integer'],
                'status' => ['type' => 'string'],
            ],
            'required' => ['story_id', 'status'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::patch("/stories/{$args['story_id']}/move", [
            'status' => $args['status'],
        ], $user);
    }
}
