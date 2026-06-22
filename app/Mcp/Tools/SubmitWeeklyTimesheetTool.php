<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class SubmitWeeklyTimesheetTool extends Tool
{
    public function name(): string { return 'submit_weekly_timesheet'; }

    public function description(): string
    {
        return 'Submit or update your own weekly timesheet — the company-wide Friday work record. '
            .'Upserts one entry per week; omit week_start for the current week (Mon–Sun, IST). '
            .'Regular and overtime hours are logged separately, and each needs a short summary (min 10 chars) when its hours are above 0. '
            .'On Fri/Sat/Sun this also clears your weekly-timesheet sign-off blocker. Always acts on your own week.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'week_start' => ['type' => 'string', 'description' => 'Any date (YYYY-MM-DD) in the target week; snapped to that week\'s Monday (IST). Omit for the current week. Future weeks are rejected.'],
                'regular_hours' => ['type' => 'number', 'description' => 'Regular hours worked this week (0–168).'],
                'overtime_hours' => ['type' => 'number', 'description' => 'Overtime hours worked this week (0–168). Use 0 if none.'],
                'regular_summary' => ['type' => 'string', 'description' => 'What you worked on in regular hours. Required (min 10 chars) when regular_hours > 0.'],
                'overtime_summary' => ['type' => 'string', 'description' => 'What you worked on as overtime. Required (min 10 chars) when overtime_hours > 0.'],
                'overtime_saturday' => ['type' => 'boolean', 'description' => 'Overtime included Saturday work.'],
                'overtime_sunday' => ['type' => 'boolean', 'description' => 'Overtime included Sunday work.'],
            ],
            'required' => ['regular_hours', 'overtime_hours'],
            'additionalProperties' => false,
        ];
    }

    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::post('/weekly-timesheet', $args, $user);
    }

    /**
     * Everyone fills a weekly timesheet except the configured exclusions
     * (JP #1). Mirrors WeeklyTimesheetController::isExcluded(); there is no
     * weeklyTimesheet feature key in UserFeatureService, so gate by config.
     */
    public function isAvailableTo(User $user): bool
    {
        $excluded = array_map('intval', config('weekly_timesheet.excluded_user_ids', []));

        return ! in_array((int) $user->id, $excluded, true);
    }
}
