<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class ListJobDescriptionsTool extends Tool
{
    public function name(): string { return 'list_job_descriptions'; }
    public function description(): string
    {
        return 'List job descriptions (open roles) in the hiring/ATS pipeline, scoped to what you can see.';
    }
    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::get('/hiring/job-descriptions', [], $user);
    }
}
