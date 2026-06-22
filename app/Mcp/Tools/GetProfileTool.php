<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class GetProfileTool extends Tool
{
    public function name(): string { return 'get_profile'; }
    public function description(): string
    {
        return 'Get your own employee profile — personal details, contact info, documents on file, and onboarding/profile-completion status.';
    }
    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::get('/profile', [], $user);
    }
}
