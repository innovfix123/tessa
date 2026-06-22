<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class ListActionItemsTool extends Tool
{
    public function name(): string { return 'list_action_items'; }
    public function description(): string
    {
        return 'List meeting action items (the follow-ups captured in meeting minutes), scoped to meetings you can see. Filter by meeting_id (the meeting key, e.g. "tech-lead-standup-fri"), status (pending/in_progress/done), owner (name), or week_key. Defaults to the 50 most recent.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'meeting_id' => ['type' => 'string', 'description' => 'Meeting key (slug), e.g. "tech-lead-standup-fri".'],
                'owner' => ['type' => 'string', 'description' => 'Filter by owner name (substring match).'],
                'status' => ['type' => 'string', 'enum' => ['pending', 'in_progress', 'done']],
                'week_key' => ['type' => 'string', 'description' => 'Monday of the week, YYYY-MM-DD.'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200],
            ],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::get('/action-items', $args, $user);
    }
}
