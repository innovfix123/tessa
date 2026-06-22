<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class ListCandidatesTool extends Tool
{
    public function name(): string { return 'list_candidates'; }
    public function description(): string
    {
        return 'List candidates for a specific job description (ATS). Visibility scoped by HR / panel / freelancer (the API enforces it).';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'job_description_id' => ['type' => 'integer'],
            ],
            'required' => ['job_description_id'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::get("/hiring/job-descriptions/{$args['job_description_id']}/candidates", [], $user);
    }
}
