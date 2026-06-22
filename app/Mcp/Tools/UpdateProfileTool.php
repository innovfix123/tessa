<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class UpdateProfileTool extends Tool
{
    public function name(): string { return 'update_profile'; }
    public function description(): string
    {
        return 'Update your own personal details (contact, address, qualification, nominee, date of birth). Only the fields you pass are changed. This edits your own record only.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'personal_mobile' => ['type' => 'string'],
                'personal_email' => ['type' => 'string'],
                'emergency_contact_name' => ['type' => 'string'],
                'emergency_contact_number' => ['type' => 'string'],
                'current_address' => ['type' => 'string'],
                'permanent_address' => ['type' => 'string'],
                'blood_group' => ['type' => 'string'],
                'marital_status' => ['type' => 'string', 'enum' => ['unmarried', 'married', 'divorced']],
                'qualification' => ['type' => 'string'],
                'date_of_birth' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                'nominee_name' => ['type' => 'string'],
                'nominee_age' => ['type' => 'integer'],
                'nominee_dob' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                'nominee_relation' => ['type' => 'string'],
            ],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        // EmployeeController::profileStore switches on `action`; the rich
        // self-edit (contact/address/nominee/DOB) lives under update_personal_info
        // and only writes the fields actually present.
        return ApiSubRequest::post('/profile', array_merge($args, ['action' => 'update_personal_info']), $user);
    }
}
