<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class ListRecruitersTool extends Tool
{
    public function name(): string { return 'list_recruiters'; }
    public function description(): string
    {
        return 'List freelance recruiters and their share links.';
    }
    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::get('/hiring/recruiters', [], $user);
    }
}
