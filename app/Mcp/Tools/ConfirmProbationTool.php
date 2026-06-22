<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class ConfirmProbationTool extends Tool
{
    private const HR_WRITE_ROLES = ['ceo', 'coo', 'cfo', 'hr', 'hr_operations', 'business_analyst'];

    public function name(): string { return 'confirm_probation'; }
    public function description(): string
    {
        return 'Confirm an employee off probation (mark them a confirmed employee). HR/exec only.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['user_id' => ['type' => 'integer']],
            'required' => ['user_id'],
            'additionalProperties' => false,
        ];
    }
    public function allowedRoleSlugs(): ?array
    {
        return self::HR_WRITE_ROLES;
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::post('/hr/probation/confirm', ['user_id' => $args['user_id']], $user);
    }
}
