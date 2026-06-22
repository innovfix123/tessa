<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class CreateDashboardNoteTool extends Tool
{
    public function name(): string { return 'create_dashboard_note'; }
    public function description(): string
    {
        return 'Create a dashboard sticky note on the signed-in user\'s portal home. Either provide body (text) or items (checklist).';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string'],
                'body' => ['type' => 'string'],
                'items' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'text' => ['type' => 'string'],
                            'checked' => ['type' => 'boolean'],
                        ],
                        'required' => ['text'],
                    ],
                ],
                'is_pinned' => ['type' => 'boolean'],
                'reminder_interval' => ['type' => 'string', 'enum' => ['10', '15', '30', '45', '60']],
                'reminder_at' => ['type' => 'string'],
            ],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        if (isset($args['reminder_interval'])) {
            $args['reminder_interval'] = (int) $args['reminder_interval'];
        }
        return ApiSubRequest::post('/notes', $args, $user);
    }
}
