<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class ExtendProbationTool extends Tool
{
    private const HR_WRITE_ROLES = ['ceo', 'coo', 'cfo', 'hr', 'hr_operations', 'business_analyst'];

    public function name(): string { return 'extend_probation'; }
    public function description(): string
    {
        return 'Extend an employee\'s probation by 15 or 30 days. HR/exec only.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'user_id' => ['type' => 'integer'],
                'days' => ['type' => 'integer', 'enum' => [15, 30]],
            ],
            'required' => ['user_id', 'days'],
            'additionalProperties' => false,
        ];
    }
    public function allowedRoleSlugs(): ?array
    {
        return self::HR_WRITE_ROLES;
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::post('/hr/probation/extend', $args, $user);
    }
}
