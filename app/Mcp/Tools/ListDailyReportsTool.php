<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ListDailyReportsTool extends Tool
{
    public function name(): string { return 'list_daily_reports'; }
    public function description(): string
    {
        return 'List daily reports (signin/signoff KPI values). Filter by date range and user_id. Defaults to the signed-in user. Returns the field schema as well as the row values, so you can discover field_keys for update_daily_report_field.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'user_id' => ['type' => 'integer', 'description' => 'Whose report (defaults to you). Only managers/admins may read other people.'],
                'report_date' => ['type' => 'string', 'description' => 'A single day YYYY-MM-DD — returns that day\'s KPI entries.'],
                'week_key' => ['type' => 'string', 'description' => 'Monday of a week YYYY-MM-DD — returns the whole week. Defaults to the current week when neither report_date nor week_key is given.'],
            ],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        // DailyReportController::index requires user_id AND (report_date | week_key).
        // Default to the signed-in user and the current ISO week so a bare call works.
        $query = ['user_id' => $args['user_id'] ?? $user->id];
        if (! empty($args['report_date'])) {
            $query['report_date'] = $args['report_date'];
        } else {
            $query['week_key'] = $args['week_key']
                ?? Carbon::now('Asia/Kolkata')->startOfWeek(Carbon::MONDAY)->format('Y-m-d');
        }
        return ApiSubRequest::get('/daily-reports', $query, $user);
    }
}
