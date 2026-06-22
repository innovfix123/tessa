<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class UpdateChecklistTool extends Tool
{
    public function name(): string { return 'update_checklist'; }
    public function description(): string
    {
        return 'Edit a daily checklist you assigned — rename it, change its description, or replace its item list. Only the assigner can edit it.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'checklist_id' => ['type' => 'integer'],
                'title' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'items' => [
                    'type' => 'array',
                    'description' => 'Full replacement item list. Each item: {id?: existing item id, title: string}.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'title' => ['type' => 'string'],
                        ],
                        'required' => ['title'],
                    ],
                ],
            ],
            'required' => ['checklist_id'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        $id = $args['checklist_id'];
        unset($args['checklist_id']);
        return ApiSubRequest::patch("/tessa/checklists/{$id}", $args, $user);
    }
}
