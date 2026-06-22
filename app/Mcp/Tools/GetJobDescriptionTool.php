<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class GetJobDescriptionTool extends Tool
{
    public function name(): string { return 'get_job_description'; }
    public function description(): string
    {
        return 'Get one job description with its details and assigned recruiters.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['job_description_id' => ['type' => 'integer']],
            'required' => ['job_description_id'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::get("/hiring/job-descriptions/{$args['job_description_id']}", [], $user);
    }
}
