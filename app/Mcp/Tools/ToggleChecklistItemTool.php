<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class ToggleChecklistItemTool extends Tool
{
    public function name(): string { return 'toggle_checklist_item'; }
    public function description(): string
    {
        return 'Tick (or untick) a daily checklist item for today. Defaults checked=true. Discover checklist_id + item_id via list_checklists. You must be the checklist\'s assignee.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'checklist_id' => ['type' => 'integer'],
                'item_id' => ['type' => 'integer'],
                'checked' => ['type' => 'boolean', 'description' => 'true = tick (default), false = untick.'],
                'note' => ['type' => 'string', 'description' => 'Optional note for today.'],
            ],
            'required' => ['checklist_id', 'item_id'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        $body = [];
        if (isset($args['checked'])) {
            $body['checked'] = (bool) $args['checked'];
        }
        if (isset($args['note'])) {
            $body['note'] = $args['note'];
        }
        return ApiSubRequest::post("/tessa/checklists/{$args['checklist_id']}/items/{$args['item_id']}/toggle", $body, $user);
    }
}
