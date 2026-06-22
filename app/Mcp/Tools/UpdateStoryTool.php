<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class UpdateStoryTool extends Tool
{
    public function name(): string { return 'update_story'; }
    public function description(): string
    {
        return 'Edit a story — its title, description, acceptance criteria, epic/sprint, assignee, priority, or points. (Use update_story_status to only move it between lanes.)';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'story_id' => ['type' => 'integer'],
                'title' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'acceptance_criteria' => ['type' => 'string'],
                'technical_notes' => ['type' => 'string'],
                'epic_id' => ['type' => 'integer'],
                'sprint_id' => ['type' => 'integer'],
                'assignee_id' => ['type' => 'integer'],
                'priority' => ['type' => 'string', 'description' => 'Use list_stories to see valid priorities.'],
                'story_points' => ['type' => 'integer', 'description' => '1-21'],
            ],
            'required' => ['story_id'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        $id = $args['story_id'];
        unset($args['story_id']);
        return ApiSubRequest::put("/stories/{$id}", $args, $user);
    }
}
