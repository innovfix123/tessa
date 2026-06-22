<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;

class AdminDailyReportsOverviewTool extends Tool
{
    public function name(): string { return 'admin_daily_reports_overview'; }
    public function description(): string
    {
        return 'Admin / CEO: who has filled daily reports and who is behind. Used by JP to nudge ICs.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'report_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD (defaults to yesterday).'],
            ],
            'additionalProperties' => false,
        ];
    }
    public function allowedRoleSlugs(): ?array
    {
        return [Role::SLUG_ADMIN, Role::SLUG_CEO];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        // Controller reads ?report_date, not ?date.
        $query = [];
        if (isset($args['report_date'])) {
            $query['report_date'] = $args['report_date'];
        }
        return ApiSubRequest::get('/admin/daily-reports-overview', $query, $user);
    }
}
