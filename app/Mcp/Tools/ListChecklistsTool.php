<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class ListChecklistsTool extends Tool
{
    public function name(): string { return 'list_checklists'; }
    public function description(): string
    {
        return 'List daily checklists. filter=mine (assigned to you, default) or assigned (ones you created). Returns checklist ids + item ids and today\'s tick state — use those ids with toggle_checklist_item.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'filter' => ['type' => 'string', 'enum' => ['mine', 'assigned']],
            ],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        $query = [];
        if (isset($args['filter'])) {
            $query['filter'] = $args['filter'];
        }
        return ApiSubRequest::get('/tessa/checklists', $query, $user);
    }
}
