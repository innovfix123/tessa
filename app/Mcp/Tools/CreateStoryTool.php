<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class CreateStoryTool extends Tool
{
    public function name(): string { return 'create_story'; }
    public function description(): string
    {
        return 'Create an agile user story. Use list_squads + list_sprints to look up the right squad_id / sprint_id first.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'sprint_id' => ['type' => 'integer'],
                'epic_id' => ['type' => 'integer'],
                'assignee_id' => ['type' => 'integer'],
                'story_points' => ['type' => 'integer'],
                'priority' => ['type' => 'string'],
            ],
            'required' => ['title'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::post('/stories', $args, $user);
    }
}
