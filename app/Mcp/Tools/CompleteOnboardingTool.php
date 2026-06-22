<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class CompleteOnboardingTool extends Tool
{
    public function name(): string { return 'complete_onboarding'; }
    public function description(): string
    {
        return 'Mark your own new-hire onboarding as complete (unlocks Daily Reports). No-op if you are already onboarded.';
    }
    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::post('/hiring/onboarding/complete', [], $user);
    }
}
