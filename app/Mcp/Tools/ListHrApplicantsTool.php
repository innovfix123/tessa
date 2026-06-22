<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class ListHrApplicantsTool extends Tool
{
    public function name(): string { return 'list_hr_applicants'; }
    public function description(): string
    {
        return 'List freelance-HR resume applicants and their screening status.';
    }
    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
    }

    // Gated by the hr_resumes feature permission (not in UserFeatureService, so check the permission directly).
    public function requiredPermission(): ?string
    {
        return 'feature.hr_resumes';
    }

    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::get('/hr-applicants', [], $user);
    }
}
