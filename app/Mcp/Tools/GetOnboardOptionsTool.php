<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class GetOnboardOptionsTool extends Tool
{
    public function name(): string { return 'get_onboard_options'; }
    public function description(): string
    {
        return 'Get the role and reporting-manager options for onboarding a candidate (use before add_candidate_to_team / onboard_candidate).';
    }
    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::get('/hiring/onboard-options', [], $user);
    }
}
