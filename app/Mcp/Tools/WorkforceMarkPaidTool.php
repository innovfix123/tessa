<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class WorkforceMarkPaidTool extends Tool
{
    public function name(): string { return 'workforce_mark_paid'; }
    public function description(): string
    {
        return 'Mark one person\'s workforce OT payment for a week as paid (optionally with a UTR number and note).';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'user_id' => ['type' => 'integer'],
                'week_start' => ['type' => 'string', 'description' => 'YYYY-MM-DD (a Monday).'],
                'utr_number' => ['type' => 'string'],
                'admin_note' => ['type' => 'string'],
            ],
            'required' => ['user_id', 'week_start'],
            'additionalProperties' => false,
        ];
    }

    public function allowedRoleSlugs(): ?array
    {
        return ['admin', 'accountant', 'ceo', 'cfo'];
    }

    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::post('/workforce/payments/mark-paid', $args, $user);
    }
}
