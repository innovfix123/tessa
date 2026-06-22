<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class GetDreamStoryTool extends Tool
{
    public function name(): string { return 'get_dream_story'; }
    public function description(): string
    {
        return 'Get today\'s Tessa "dream story" dashboard widget.';
    }
    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::get('/tessa/dream-story', [], $user);
    }
}
