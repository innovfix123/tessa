<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class ManagerRatingsOverviewTool extends Tool
{
    public function name(): string { return 'manager_ratings_overview'; }
    public function description(): string
    {
        return 'CEO view: the Friday Work-Quality ratings grid across all managers and their reports for the last N weeks (who rated whom, and the scores).';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'weeks' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 12, 'description' => 'How many recent weeks (default 6).'],
            ],
            'additionalProperties' => false,
        ];
    }

    // CEO-only surface.
    public function allowedRoleSlugs(): ?array
    {
        return ['ceo'];
    }

    public function handle(array $args, User $user, Request $request): mixed
    {
        $query = [];
        if (isset($args['weeks'])) {
            $query['weeks'] = $args['weeks'];
        }
        return ApiSubRequest::get('/manager-ratings/overview', $query, $user);
    }
}
