<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class ListTasksTool extends Tool
{
    public function name(): string { return 'list_tasks'; }
    public function description(): string
    {
        return 'List Tessa tasks. Filter by status (pending/in_progress/completed/blocked/cancelled), assignee_id, or a deadline window (due_after / due_before as YYYY-MM-DD).';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'status' => ['type' => 'string', 'enum' => ['pending', 'in_progress', 'completed', 'blocked', 'cancelled']],
                'assignee_id' => ['type' => 'integer'],
                'due_after' => ['type' => 'string', 'description' => 'Only tasks due on/after this date (YYYY-MM-DD).'],
                'due_before' => ['type' => 'string', 'description' => 'Only tasks due on/before this date (YYYY-MM-DD).'],
            ],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        // The controller reads deadline_from/deadline_to, not due_after/due_before.
        $query = [];
        foreach (['status', 'assignee_id'] as $k) {
            if (isset($args[$k])) {
                $query[$k] = $args[$k];
            }
        }
        if (isset($args['due_after'])) {
            $query['deadline_from'] = $args['due_after'];
        }
        if (isset($args['due_before'])) {
            $query['deadline_to'] = $args['due_before'];
        }
        return ApiSubRequest::get('/tessa/tasks', $query, $user);
    }
}
