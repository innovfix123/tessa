<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class MoveBugTool extends Tool
{
    public function name(): string { return 'move_bug'; }
    public function description(): string
    {
        return 'Move a bug between board lanes (e.g. open → in_progress → resolved).';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'bug_id' => ['type' => 'integer'],
                'status' => ['type' => 'string', 'description' => 'Use list_bugs to see valid statuses.'],
                'sort_order' => ['type' => 'integer'],
            ],
            'required' => ['bug_id', 'status'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        $id = $args['bug_id'];
        unset($args['bug_id']);
        return ApiSubRequest::patch("/bugs/{$id}/move", $args, $user);
    }
}
