<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class GetMyWeeklyTimesheetTool extends Tool
{
    public function name(): string { return 'get_my_weekly_timesheet'; }

    public function description(): string
    {
        return 'Get your own weekly timesheet entry for a week — defaults to the current week (Mon–Sun, IST). '
            .'Returns the saved regular/overtime hours and summaries plus whether you can still fill or edit it. '
            .'Use this before submit_weekly_timesheet to see what is already logged.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'week' => ['type' => 'string', 'description' => 'Any date (YYYY-MM-DD) in the target week; snapped to that week\'s Monday (IST). Omit for the current week.'],
            ],
            'required' => [],
            'additionalProperties' => false,
        ];
    }

    public function handle(array $args, User $user, Request $request): mixed
    {
        $query = [];
        if (! empty($args['week'])) {
            $query['week'] = $args['week'];
        }

        return ApiSubRequest::get('/weekly-timesheet/mine', $query, $user);
    }

    /**
     * Same audience as the submit tool — everyone except the configured
     * exclusions (JP #1). Mirrors WeeklyTimesheetController::isExcluded().
     */
    public function isAvailableTo(User $user): bool
    {
        $excluded = array_map('intval', config('weekly_timesheet.excluded_user_ids', []));

        return ! in_array((int) $user->id, $excluded, true);
    }
}
