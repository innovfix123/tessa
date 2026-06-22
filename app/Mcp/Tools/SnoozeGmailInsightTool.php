<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class SnoozeGmailInsightTool extends Tool
{
    public function name(): string { return 'snooze_gmail_insight'; }
    public function description(): string
    {
        return 'Snooze a Gmail insight card until a future datetime (it reappears then).';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'insight_id' => ['type' => 'integer'],
                'until' => ['type' => 'string', 'description' => 'Future datetime (ISO 8601 or YYYY-MM-DD HH:MM), must be after now.'],
            ],
            'required' => ['insight_id', 'until'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::post("/gmail/insights/{$args['insight_id']}/snooze", ['until' => $args['until']], $user);
    }
}
