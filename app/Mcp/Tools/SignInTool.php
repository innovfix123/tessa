<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class SignInTool extends Tool
{
    public function name(): string { return 'sign_in'; }
    public function description(): string
    {
        return 'Record your daily attendance sign-in (IST). Idempotent — calling again when already signed in is a no-op. This unlocks the rest of the portal for the day.';
    }
    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::post('/signin', [], $user);
    }
}
