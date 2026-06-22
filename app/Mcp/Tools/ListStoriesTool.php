<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class ListStoriesTool extends Tool
{
    public function name(): string { return 'list_stories'; }
    public function description(): string
    {
        return 'List user stories. Filter by sprint_id, epic_id, assignee_id, or status.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'sprint_id' => ['type' => 'integer'],
                'epic_id' => ['type' => 'integer'],
                'assignee_id' => ['type' => 'integer'],
                'status' => ['type' => 'string'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200],
            ],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::get('/stories', $args, $user);
    }
}
