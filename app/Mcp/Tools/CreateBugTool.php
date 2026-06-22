<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class CreateBugTool extends Tool
{
    public function name(): string { return 'create_bug'; }
    public function description(): string
    {
        return 'File a bug. Provide a clear title + steps to reproduce; severity is low/medium/high (defaults to medium). Optionally attach it to a project.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'steps_to_reproduce' => ['type' => 'string'],
                'project_id' => ['type' => 'integer', 'description' => 'Optional project to file the bug under.'],
                'severity' => ['type' => 'string', 'enum' => ['low', 'medium', 'high']],
            ],
            'required' => ['title'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::post('/bugs', $args, $user);
    }
}
