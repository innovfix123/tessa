<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class CreateSquadTool extends Tool
{
    public function name(): string { return 'create_squad'; }
    public function description(): string
    {
        return 'Create an agile squad (team) with an optional lead and definition of ready.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'lead_user_id' => ['type' => 'integer'],
                'definition_of_ready' => ['type' => 'string'],
            ],
            'required' => ['name'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::post('/squads', $args, $user);
    }
}
