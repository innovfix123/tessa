<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class UpdateBugTool extends Tool
{
    public function name(): string { return 'update_bug'; }
    public function description(): string
    {
        return 'Edit a bug — its title, description, repro steps, epic, assignee, severity, priority, or points. (Use move_bug to only change its status.)';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'bug_id' => ['type' => 'integer'],
                'title' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'steps_to_reproduce' => ['type' => 'string'],
                'epic_id' => ['type' => 'integer'],
                'assignee_id' => ['type' => 'integer'],
                'severity' => ['type' => 'string', 'description' => 'Use list_bugs to see valid severities.'],
                'priority' => ['type' => 'string', 'description' => 'Use list_bugs to see valid priorities.'],
                'story_points' => ['type' => 'integer', 'description' => '1-21'],
            ],
            'required' => ['bug_id'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        $id = $args['bug_id'];
        unset($args['bug_id']);
        return ApiSubRequest::put("/bugs/{$id}", $args, $user);
    }
}
