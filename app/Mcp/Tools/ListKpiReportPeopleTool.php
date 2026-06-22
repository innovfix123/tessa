<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\KpiScorecardItem;
use App\Models\User;
use Illuminate\Http\Request;

class ListKpiReportPeopleTool extends Tool
{
    public function name(): string { return 'list_kpi_report_people'; }
    public function description(): string
    {
        return 'List the people whose weekly KPI report notes you fill (and this week\'s fill status), plus your own KPI status. Admins also get every eligible subject.';
    }
    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
    }

    // Visible to KPI-report fillers (managers) and the configured admins.
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
        return ApiSubRequest::get('/kpi-report/people', [], $user);
    }
}
