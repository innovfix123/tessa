<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class AssignRecruitersTool extends Tool
{
    public function name(): string { return 'assign_recruiters'; }
    public function description(): string
    {
        return 'Assign one or more recruiters (by user id) to a job description.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'job_description_id' => ['type' => 'integer'],
                'recruiter_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
            ],
            'required' => ['job_description_id', 'recruiter_ids'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::post("/hiring/job-descriptions/{$args['job_description_id']}/assign", [
            'recruiter_ids' => $args['recruiter_ids'],
        ], $user);
    }
}
