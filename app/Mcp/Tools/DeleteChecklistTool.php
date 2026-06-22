<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class DeleteChecklistTool extends Tool
{
    public function name(): string { return 'delete_checklist'; }
    public function description(): string
    {
        return 'Delete a daily checklist you created (and its items). Get the checklist id from list_checklists (filter=assigned).';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['checklist_id' => ['type' => 'integer']],
            'required' => ['checklist_id'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::delete("/tessa/checklists/{$args['checklist_id']}", [], $user);
    }
}
