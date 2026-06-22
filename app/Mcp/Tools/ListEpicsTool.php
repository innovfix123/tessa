<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class ListEpicsTool extends Tool
{
    public function name(): string { return 'list_epics'; }
    public function description(): string
    {
        return 'List agile epics. Filter by squad_id or status.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'squad_id' => ['type' => 'integer'],
                'status' => ['type' => 'string'],
            ],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::get('/epics', $args, $user);
    }
}
