<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class DeleteKpiReportItemTool extends Tool
{
    public function name(): string { return 'delete_kpi_report_item'; }
    public function description(): string
    {
        return 'Deactivate (soft-delete) a KPI definition, keeping its weekly history and AI summaries. Restricted to the configured KPI-report admins.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['item_id' => ['type' => 'integer']],
            'required' => ['item_id'],
            'additionalProperties' => false,
        ];
    }

    public function isAvailableTo(User $user): bool
    {
        $admins = array_map('intval', (array) config('kpi_report.admin_user_ids', []));
        return in_array((int) $user->id, $admins, true);
    }

    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::delete("/kpi-report/items/{$args['item_id']}", [], $user);
    }
}
