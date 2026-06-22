<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\KpiScorecardItem;
use App\Models\User;
use Illuminate\Http\Request;

class GetKpiReportTool extends Tool
{
    public function name(): string { return 'get_kpi_report'; }
    public function description(): string
    {
        return 'Get one person\'s KPI scorecard — their KPI items, recent weekly notes and AI summaries. You must fill their report (or be an admin).';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['user_id' => ['type' => 'integer', 'description' => 'The subject whose scorecard to fetch.']],
            'required' => ['user_id'],
            'additionalProperties' => false,
        ];
    }

    public function isAvailableTo(User $user): bool
    {
        $admins = array_map('intval', (array) config('kpi_report.admin_user_ids', []));
        if (in_array((int) $user->id, $admins, true)) {
            return true;
        }
        return KpiScorecardItem::fillableSubjectsFor($user)->isNotEmpty();
    }

    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::get("/kpi-report/user/{$args['user_id']}", [], $user);
    }
}
