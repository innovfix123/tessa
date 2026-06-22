<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class CreateRecurrenceTool extends Tool
{
    public function name(): string { return 'create_recurrence'; }
    public function description(): string
    {
        return 'Create a recurring task template that auto-spawns a task daily, weekly, or monthly for the assignee.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'assigned_to' => ['type' => 'integer', 'description' => 'User id to assign each spawned task to.'],
                'priority' => ['type' => 'string', 'enum' => ['low', 'medium', 'high', 'urgent']],
                'recurrence_type' => ['type' => 'string', 'enum' => ['daily', 'weekly', 'monthly']],
                'recurrence_day' => ['type' => 'integer', 'description' => 'For weekly: 0-6 (Sun-Sat). For monthly: 1-28. Omit for daily.'],
                'deadline_offset_hours' => ['type' => 'integer', 'description' => 'Hours after spawn that each task is due (1-720).'],
            ],
            'required' => ['title', 'assigned_to', 'recurrence_type'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::post('/tessa/recurrences', $args, $user);
    }
}
