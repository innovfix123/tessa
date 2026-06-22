<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class AddKpiReportItemTool extends Tool
{
    public function name(): string { return 'add_kpi_report_item'; }
    public function description(): string
    {
        return 'Add a KPI definition to a person\'s scorecard. Restricted to the configured KPI-report admins.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'user_id' => ['type' => 'integer'],
                'name' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'target' => ['type' => 'string'],
                'weight' => ['type' => 'integer', 'description' => '0-100.'],
                'sort_order' => ['type' => 'integer'],
            ],
            'required' => ['user_id', 'name'],
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
        return ApiSubRequest::post('/kpi-report/items', $args, $user);
    }
}
