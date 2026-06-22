<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class CreateDesignationTool extends Tool
{
    private const HR_WRITE_ROLES = ['ceo', 'coo', 'cfo', 'hr', 'hr_operations', 'business_analyst'];

    public function name(): string { return 'create_designation'; }
    public function description(): string
    {
        return 'Create a designation/title (or rename one by passing its id). HR/exec only.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string'],
                'id' => ['type' => 'integer', 'description' => 'Pass to rename an existing designation.'],
            ],
            'required' => ['title'],
            'additionalProperties' => false,
        ];
    }
    public function allowedRoleSlugs(): ?array
    {
        return self::HR_WRITE_ROLES;
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::post('/designations', $args, $user);
    }
}
