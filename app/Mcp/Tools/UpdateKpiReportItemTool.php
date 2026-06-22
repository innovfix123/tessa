<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class UpdateKpiReportItemTool extends Tool
{
    public function name(): string { return 'update_kpi_report_item'; }
    public function description(): string
    {
        return 'Edit a KPI definition (name, description, target, weight, order) or deactivate it. Restricted to the configured KPI-report admins.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'item_id' => ['type' => 'integer'],
                'name' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'target' => ['type' => 'string'],
                'weight' => ['type' => 'integer', 'description' => '0-100.'],
                'sort_order' => ['type' => 'integer'],
                'is_active' => ['type' => 'boolean', 'description' => 'false soft-deactivates (keeps history).'],
            ],
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
        $id = $args['item_id'];
        unset($args['item_id']);
        return ApiSubRequest::patch("/kpi-report/items/{$id}", $args, $user);
    }
}
