<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class AddTaskCheckinTool extends Tool
{
    public function name(): string { return 'add_task_checkin'; }
    public function description(): string
    {
        return 'Add a daily progress check-in on a task: health status + percent complete, with an optional note. One update per day.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'task_id' => ['type' => 'integer'],
                'health_status' => ['type' => 'string', 'enum' => ['on_track', 'at_risk', 'blocked'], 'description' => 'How is the task tracking?'],
                'progress' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100, 'description' => 'Percent complete (0–100).'],
                'note' => ['type' => 'string', 'description' => 'What you accomplished / anything blocking you.'],
                'checkin_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD. Defaults to today (IST); cannot be in the future.'],
            ],
            'required' => ['task_id', 'health_status', 'progress'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        $taskId = $args['task_id'];
        unset($args['task_id']);
        return ApiSubRequest::post("/tessa/tasks/{$taskId}/checkins", $args, $user);
    }
}
