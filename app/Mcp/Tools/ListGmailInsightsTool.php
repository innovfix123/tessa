<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class ListGmailInsightsTool extends Tool
{
    public function name(): string { return 'list_gmail_insights'; }
    public function description(): string
    {
        return 'List your AI-classified important-email insight cards (surfaced from Gmail).';
    }
    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::get('/gmail/insights', [], $user);
    }
}
