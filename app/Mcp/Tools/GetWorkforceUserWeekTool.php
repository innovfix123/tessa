<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class GetWorkforceUserWeekTool extends Tool
{
    public function name(): string { return 'get_workforce_user_week'; }
    public function description(): string
    {
        return 'Get one user\'s workforce OT payment detail for a week.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'user_id' => ['type' => 'integer'],
                'week_start' => ['type' => 'string', 'description' => 'YYYY-MM-DD (a Monday).'],
            ],
            'required' => ['user_id'],
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
        return ApiSubRequest::get("/workforce/payments/user/{$args['user_id']}", $query, $user);
    }
}
