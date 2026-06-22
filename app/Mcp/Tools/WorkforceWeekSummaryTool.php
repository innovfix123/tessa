<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class WorkforceWeekSummaryTool extends Tool
{
    public function name(): string { return 'workforce_week_summary'; }
    public function description(): string
    {
        return 'Get the workforce OT payment summary for a week (totals paid/pending).';
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
        return ApiSubRequest::get('/workforce/payments/week-summary', $query, $user);
    }
}
