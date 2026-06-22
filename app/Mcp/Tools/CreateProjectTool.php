<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class CreateProjectTool extends Tool
{
    public function name(): string { return 'create_project'; }
    public function description(): string
    {
        return 'Create an agile project (name must be unique).';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['name' => ['type' => 'string']],
            'required' => ['name'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::post('/projects', $args, $user);
    }
}
