<?php

namespace App\Mcp\Tools;

use App\Mcp\ApiSubRequest;
use App\Mcp\Tool;
use App\Models\User;
use Illuminate\Http\Request;

class CreateReminderTool extends Tool
{
    public function name(): string { return 'create_reminder'; }
    public function description(): string
    {
        return 'Set a reminder for the signed-in user — it appears as a card on their Tessa dashboard. '
            .'Choose EXACTLY ONE schedule: reminder_interval (repeat every N minutes — use this for "every 15 minutes"), '
            .'reminder_at (one-shot at a specific date-time), or reminder_day (monthly, on a day of the month). '
            .'Call whoami for today\'s date so reminder_at is never in the past.';
    }
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'text' => ['type' => 'string', 'description' => 'What to be reminded about (shown on the dashboard card).'],
                'reminder_interval' => ['type' => 'integer', 'enum' => [10, 15, 30, 45, 60], 'description' => 'Repeat every N minutes (10/15/30/45/60).'],
                'reminder_at' => ['type' => 'string', 'description' => 'One-shot reminder at this date-time — YYYY-MM-DD HH:MM (IST).'],
                'reminder_day' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 31, 'description' => 'Monthly reminder, on this day of the month.'],
            ],
            'required' => ['text'],
            'additionalProperties' => false,
        ];
    }
    public function handle(array $args, User $user, Request $request): mixed
    {
        // The dashboard renders a note by its checklist items (public/js/portal.js),
        // so the reminder text must be an item — not just a title, which never shows.
        // The three reminder modes are mutually exclusive server-side
        // (DashboardNoteController::resolveReminder): monthly > one-shot > interval.
        $payload = ['items' => [['text' => $args['text']]]];
        foreach (['reminder_interval', 'reminder_at', 'reminder_day'] as $k) {
            if (isset($args[$k])) {
                $payload[$k] = $args[$k];
            }
        }
        return ApiSubRequest::post('/notes', $payload, $user);
    }
}
