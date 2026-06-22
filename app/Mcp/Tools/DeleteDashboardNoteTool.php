<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class DeleteDashboardNoteTool extends Tool
{
    public function name(): string { return 'delete_dashboard_note'; }
    public function description(): string
    {
        return 'Delete a dashboard sticky note.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['note_id' => ['type' => 'integer']],
            'required' => ['note_id'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::delete("/notes/{$args['note_id']}", [], $user);
    }
}
