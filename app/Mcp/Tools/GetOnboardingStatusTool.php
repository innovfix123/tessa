<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class GetOnboardingStatusTool extends Tool
{
    public function name(): string { return 'get_onboarding_status'; }
    public function description(): string
    {
        return 'Get your own new-hire onboarding checklist and completion status. (Available to every employee; returns "already onboarded" once complete.)';
    }
    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::get('/hiring/onboarding', [], $user);
    }
}
