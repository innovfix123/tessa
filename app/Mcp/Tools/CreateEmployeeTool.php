<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class CreateEmployeeTool extends Tool
{
    private const HR_WRITE_ROLES = ['ceo', 'coo', 'cfo', 'hr', 'hr_operations', 'business_analyst'];

    public function name(): string { return 'create_employee'; }
    public function description(): string
    {
        return 'Add a new team member (creates their Tessa account). Defaults the password to 12345678 if none given. HR/exec only.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
                'email' => ['type' => 'string'],
                'role_id' => ['type' => 'integer'],
                'reporting_manager_id' => ['type' => 'integer'],
                'employment_type' => ['type' => 'string', 'enum' => ['full_time', 'internship', 'freelancer']],
                'joining_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD (defaults to today).'],
                'designation' => ['type' => 'string'],
                'department_id' => ['type' => 'integer'],
                'designation_id' => ['type' => 'integer'],
                'gender' => ['type' => 'string'],
                'date_of_birth' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                'personal_mobile' => ['type' => 'string'],
                'personal_email' => ['type' => 'string'],
                'notice_period_days' => ['type' => 'integer'],
                'hourly_rate' => ['type' => 'number'],
            ],
            'required' => ['name', 'email', 'role_id'],
            'additionalProperties' => false,
        ];
    }
    public function allowedRoleSlugs(): ?array
    {
        return self::HR_WRITE_ROLES;
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::post('/employees', array_merge($args, ['action' => 'create']), $user);
    }
}
