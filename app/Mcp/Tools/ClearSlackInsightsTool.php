<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class ClearSlackInsightsTool extends Tool
{
    public function name(): string { return 'clear_slack_insights'; }
    public function description(): string
    {
        return 'PERMANENTLY DELETE all your Slack insight cards (also empties the dashboard Slack card). This cannot be undone — confirm with the user before calling.';
    }
    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::delete('/slack/insights', [], $user);
    }
}
