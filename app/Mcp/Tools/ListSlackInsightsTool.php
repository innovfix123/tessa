<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class ListSlackInsightsTool extends Tool
{
    public function name(): string { return 'list_slack_insights'; }
    public function description(): string
    {
        return 'List your AI-classified Slack insight cards (action items, follow-ups, decisions surfaced from Slack).';
    }
    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::get('/slack/insights', [], $user);
    }
}
