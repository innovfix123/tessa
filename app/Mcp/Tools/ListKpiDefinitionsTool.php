<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class ListKpiDefinitionsTool extends Tool
{
    public function name(): string { return 'list_kpi_definitions'; }
    public function description(): string
    {
        return 'List KPI definitions (the schema of measurable outcomes per role). Includes target type, unit, and scope.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'role' => ['type' => 'string', 'description' => 'Role slug'],
            ],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::get('/kpi-definitions', $args, $user);
    }
}
