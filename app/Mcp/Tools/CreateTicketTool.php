<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class CreateTicketTool extends Tool
{
    public function name(): string { return 'create_ticket'; }
    public function description(): string
    {
        return 'Raise a support ticket (technical or AI). It is routed to the relevant support assignee for that category.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'category' => ['type' => 'string', 'enum' => ['technical', 'ai']],
                'priority' => ['type' => 'string', 'enum' => ['low', 'medium', 'high']],
                'assignee_id' => ['type' => 'integer', 'description' => 'Optional specific assignee; defaults by category.'],
            ],
            'required' => ['title', 'category', 'priority'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::post('/tickets', $args, $user);
    }
}
