<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class CreateLabelTool extends Tool
{
    public function name(): string { return 'create_label'; }
    public function description(): string
    {
        return 'Create an agile label (unique name, optional hex color like #3b82f6).';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
                'color' => ['type' => 'string', 'description' => 'Hex color, e.g. #3b82f6'],
            ],
            'required' => ['name'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::post('/labels', $args, $user);
    }
}
