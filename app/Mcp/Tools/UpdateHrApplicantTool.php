<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class UpdateHrApplicantTool extends Tool
{
    public function name(): string { return 'update_hr_applicant'; }
    public function description(): string
    {
        return 'Update a freelance-HR applicant\'s screening status (pending / selected / not_selected).';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'hr_applicant_id' => ['type' => 'integer'],
                'status' => ['type' => 'string', 'enum' => ['pending', 'selected', 'not_selected']],
            ],
            'required' => ['hr_applicant_id', 'status'],
            'additionalProperties' => false,
        ];
    }

    public function requiredPermission(): ?string
    {
        return 'feature.hr_resumes';
    }

    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::patch("/hr-applicants/{$args['hr_applicant_id']}", ['status' => $args['status']], $user);
    }
}
