<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class SaveChecklistItemNoteTool extends Tool
{
    public function name(): string { return 'save_checklist_item_note'; }
    public function description(): string
    {
        return 'Add or update today\'s note on a checklist item (independent of whether it is ticked). You must be the checklist\'s assignee.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'checklist_id' => ['type' => 'integer'],
                'item_id' => ['type' => 'integer'],
                'note' => ['type' => 'string', 'description' => 'Note text (max 2000 chars; empty clears it).'],
            ],
            'required' => ['checklist_id', 'item_id', 'note'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::post(
            "/tessa/checklists/{$args['checklist_id']}/items/{$args['item_id']}/note",
            ['note' => $args['note']],
            $user,
        );
    }
}
