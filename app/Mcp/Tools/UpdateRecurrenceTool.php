<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class UpdateRecurrenceTool extends Tool
{
    public function name(): string { return 'update_recurrence'; }
    public function description(): string
    {
        return 'Edit a recurring task template you created — change its details, schedule, or pause/resume it (is_active). Only the creator can edit it.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'recurrence_id' => ['type' => 'integer'],
                'is_active' => ['type' => 'boolean', 'description' => 'false pauses the recurrence.'],
                'title' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'assigned_to' => ['type' => 'integer'],
                'priority' => ['type' => 'string', 'enum' => ['low', 'medium', 'high', 'urgent']],
                'recurrence_type' => ['type' => 'string', 'enum' => ['daily', 'weekly', 'monthly']],
                'recurrence_day' => ['type' => 'integer'],
                'deadline_offset_hours' => ['type' => 'integer'],
            ],
            'required' => ['recurrence_id'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        $id = $args['recurrence_id'];
        unset($args['recurrence_id']);
        return ApiSubRequest::put("/tessa/recurrences/{$id}", $args, $user);
    }
}
