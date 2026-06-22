<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class ListSquadsTool extends Tool
{
    public function name(): string { return 'list_squads'; }
    public function description(): string
    {
        return 'List all agile squads (cross-functional product teams) in Tessa with their members.';
    }
    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::get('/squads', [], $user);
    }
}
