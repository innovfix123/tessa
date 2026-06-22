<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class UpdateEmployeeTool extends Tool
{
    private const HR_WRITE_ROLES = ['ceo', 'coo', 'cfo', 'hr', 'hr_operations', 'business_analyst'];

    public function name(): string { return 'update_employee'; }
    public function description(): string
    {
        return 'Edit an existing employee record (role, manager, designation, dates, contact). Only the fields you pass are changed. HR/exec only. (For salary use the portal; for status changes use the portal.)';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'The employee user id to edit.'],
                'role_id' => ['type' => 'integer'],
                'reporting_manager_id' => ['type' => 'integer'],
                'designation' => ['type' => 'string'],
                'department_id' => ['type' => 'integer'],
                'designation_id' => ['type' => 'integer'],
                'employment_type' => ['type' => 'string', 'enum' => ['full_time', 'internship', 'freelancer']],
                'joining_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                'probation_start_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                'probation_end_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                'notice_period_days' => ['type' => 'integer'],
                'gender' => ['type' => 'string'],
                'date_of_birth' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                'personal_mobile' => ['type' => 'string'],
                'personal_email' => ['type' => 'string'],
            ],
            'required' => ['id'],
            'additionalProperties' => false,
        ];
    }
    public function allowedRoleSlugs(): ?array
    {
        return self::HR_WRITE_ROLES;
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::post('/employees', array_merge($args, ['action' => 'update']), $user);
    }
}
