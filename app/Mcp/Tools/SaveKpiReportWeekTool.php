<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\KpiScorecardItem;
use App\Models\User;
use Illuminate\Http\Request;

class SaveKpiReportWeekTool extends Tool
{
    public function name(): string { return 'save_kpi_report_week'; }
    public function description(): string
    {
        return "Write a person's weekly KPI report notes for a week (per KPI item). Editable only on Fri-Sun and only for people whose report you fill.";
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'user_id' => ['type' => 'integer', 'description' => 'The subject whose week you are filling.'],
                'week_key' => ['type' => 'string', 'description' => 'Any date in the target week (YYYY-MM-DD).'],
                'items' => [
                    'type' => 'array',
                    'description' => 'One entry per KPI item.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'kpi_item_id' => ['type' => 'integer'],
                            'report_text' => ['type' => 'string'],
                        ],
                        'required' => ['kpi_item_id'],
                    ],
                ],
            ],
            'required' => ['user_id', 'week_key', 'items'],
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
        $id = $args['user_id'];
        return ApiSubRequest::post("/kpi-report/user/{$id}/week", [
            'week_key' => $args['week_key'],
            'items' => $args['items'],
        ], $user);
    }
}
