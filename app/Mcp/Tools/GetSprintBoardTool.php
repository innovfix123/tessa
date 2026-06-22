<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class GetSprintBoardTool extends Tool
{
    public function name(): string { return 'get_sprint_board'; }
    public function description(): string
    {
        return 'Fetch the kanban board for a sprint (lanes + cards).';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['sprint_id' => ['type' => 'integer']],
            'required' => ['sprint_id'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::get("/sprints/{$args['sprint_id']}/board", [], $user);
    }
}
