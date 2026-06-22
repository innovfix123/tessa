<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class ListBugsTool extends Tool
{
    public function name(): string { return 'list_bugs'; }
    public function description(): string
    {
        return 'List agile bugs. Filter by squad_id, sprint_id, status, or severity.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'squad_id' => ['type' => 'integer'],
                'sprint_id' => ['type' => 'integer'],
                'status' => ['type' => 'string'],
                'severity' => ['type' => 'string'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200],
            ],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::get('/bugs', $args, $user);
    }
}
