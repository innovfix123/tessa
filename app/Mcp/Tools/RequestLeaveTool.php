<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class RequestLeaveTool extends Tool
{
    public function name(): string { return 'request_leave'; }
    public function description(): string
    {
        return 'File a leave request for the signed-in user. '
            .'Casual & WFH route to the reporting manager for approval; Sick/Emergency/Menstrual auto-approve. '
            .'For Permission (hourly) pass from_time + to_time on start_date; for Compensate pass compensation_date '
            .'(the weekend day worked in exchange for the weekday off). '
            .'Call list_leave_types first to see the slugs available to this user. '
            .'Dates use the CURRENT year unless the user explicitly says otherwise — call whoami for today\'s date; never file a date in the past.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'leave_type' => [
                    'type' => 'string',
                    'enum' => ['casual', 'sick', 'emergency', 'wfh', 'menstrual', 'permission', 'compensate'],
                    'description' => 'Leave type slug (call list_leave_types for the authoritative list available to this user).',
                ],
                'start_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD. The single day for permission/compensate.'],
                'end_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD. Defaults to start_date; omit for permission/compensate.'],
                'reason' => ['type' => 'string'],
                'from_time' => ['type' => 'string', 'description' => 'HH:MM — required only for permission (hourly) leave.'],
                'to_time' => ['type' => 'string', 'description' => 'HH:MM — required only for permission (hourly) leave.'],
                'compensation_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD weekend day worked — required only for compensate leave.'],
            ],
            'required' => ['leave_type', 'start_date'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        return ApiSubRequest::post('/leave/requests', $args, $user);
    }
}
