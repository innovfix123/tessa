<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class CreateJobDescriptionTool extends Tool
{
    public function name(): string { return 'create_job_description'; }
    public function description(): string
    {
        return 'Create a job description from typed text (form mode). To upload a PDF JD, use the portal instead.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string'],
                'description' => ['type' => 'string', 'description' => 'The full JD text.'],
                'required_skills' => ['type' => 'string'],
                'experience_level' => ['type' => 'string'],
                'salary_range' => ['type' => 'string'],
            ],
            'required' => ['title', 'description'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::post('/hiring/job-descriptions', array_merge($args, ['source_type' => 'form']), $user);
    }
}
