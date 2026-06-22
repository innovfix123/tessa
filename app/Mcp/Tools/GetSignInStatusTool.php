<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class GetSignInStatusTool extends Tool
{
    public function name(): string { return 'get_signin_status'; }
    public function description(): string
    {
        return 'Get your sign-in / sign-off state for a day (defaults to today, IST), including whether you can sign off and any pending items still blocking it.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'date' => ['type' => 'string', 'description' => 'YYYY-MM-DD, defaults to today (IST).'],
            ],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        $query = [];
        if (! empty($args['date'])) {
            $query['date'] = $args['date'];
        }
        return ApiSubRequest::get('/signoff-status', $query, $user);
    }
}
