<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class ListTicketsTool extends Tool
{
    public function name(): string { return 'list_tickets'; }
    public function description(): string
    {
        return 'List support tickets. Filter by status or assignee_id.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'status' => ['type' => 'string'],
                'assignee_id' => ['type' => 'integer'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200],
            ],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::get('/tickets', $args, $user);
    }
}
