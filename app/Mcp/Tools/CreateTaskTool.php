<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class CreateTaskTool extends Tool
{
    public function name(): string { return 'create_task'; }
    public function description(): string
    {
        return 'Create a Tessa task assigned to a teammate. Use list_employees first to look up assigned_to (user id).';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string'],
                'assigned_to' => ['type' => 'integer', 'description' => 'User id of assignee'],
                'description' => ['type' => 'string'],
                'priority' => ['type' => 'string', 'enum' => ['low', 'medium', 'high', 'urgent']],
                'deadline' => ['type' => 'string', 'description' => 'YYYY-MM-DD or ISO datetime'],
                'squad_id' => ['type' => 'integer'],
            ],
            'required' => ['title', 'assigned_to'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::post('/tessa/tasks', $args, $user);
    }
}
