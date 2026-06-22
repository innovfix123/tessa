<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class CreateDepartmentTool extends Tool
{
    private const HR_WRITE_ROLES = ['ceo', 'coo', 'cfo', 'hr', 'hr_operations', 'business_analyst'];

    public function name(): string { return 'create_department'; }
    public function description(): string
    {
        return 'Create a department (or rename one by passing its id). HR/exec only.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
                'id' => ['type' => 'integer', 'description' => 'Pass to rename an existing department.'],
            ],
            'required' => ['name'],
            'additionalProperties' => false,
        ];
    }
    public function allowedRoleSlugs(): ?array
    {
        return self::HR_WRITE_ROLES;
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::post('/departments', $args, $user);
    }
}
