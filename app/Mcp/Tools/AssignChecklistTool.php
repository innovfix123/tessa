<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class AssignChecklistTool extends Tool
{
    public function name(): string { return 'assign_checklist'; }
    public function description(): string
    {
        return 'Assign a daily checklist to an employee — they tick the items off each day from their dashboard. '
            .'Use list_employees to find the assigned_to user id.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'assigned_to' => ['type' => 'integer', 'description' => 'User id the checklist is for.'],
                'title' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'items' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Checklist line items (at least one).',
                ],
            ],
            'required' => ['assigned_to', 'title', 'items'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::post('/tessa/checklists', $args, $user);
    }
}
