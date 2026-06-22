<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class ListWorkforcePaymentsTool extends Tool
{
    public function name(): string { return 'list_workforce_payments'; }
    public function description(): string
    {
        return 'List the workforce OT payment queue, optionally for a given week (week_start YYYY-MM-DD).';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['week_start' => ['type' => 'string', 'description' => 'YYYY-MM-DD (a Monday).']],
            'additionalProperties' => false,
        ];
    }

    public function allowedRoleSlugs(): ?array
    {
        return ['admin', 'accountant', 'ceo', 'cfo'];
    }

    public function handle(array $args, User $user, Request $request): mixed
    {
        $query = [];
        if (! empty($args['week_start'])) {
            $query['week_start'] = $args['week_start'];
        }
        return ApiSubRequest::get('/workforce/payments', $query, $user);
    }
}
