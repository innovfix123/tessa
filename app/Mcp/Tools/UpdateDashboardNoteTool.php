<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class UpdateDashboardNoteTool extends Tool
{
    public function name(): string { return 'update_dashboard_note'; }
    public function description(): string
    {
        return 'Update an existing dashboard sticky note (title, body, checklist items, pin state, or reminder).';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'note_id' => ['type' => 'integer'],
                'title' => ['type' => 'string'],
                'body' => ['type' => 'string'],
                'items' => ['type' => 'array'],
                'is_pinned' => ['type' => 'boolean'],
            ],
            'required' => ['note_id'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        $noteId = $args['note_id'];
        unset($args['note_id']);
        return ApiSubRequest::put("/notes/{$noteId}", $args, $user);
    }
}
