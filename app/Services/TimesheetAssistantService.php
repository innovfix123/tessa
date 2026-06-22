<?php

namespace App\Services;

use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;

class TimesheetAssistantService
{
    public function __construct(
        private TessaAIService $ai,
        private TimesheetService $timesheet
    ) {}

    /**
     * Send a message in the timesheet-collection conversation. Returns the assistant
     * reply and (when the model has gathered all required fields) a parsed payload.
     *
     * @param  array<int, array{role: string, content: string}>  $history
     * @return array{reply: string, payload: ?array}
     */
    public function chat(User $caller, string $message, array $history): array
    {
        $messages = array_merge(
            array_map(fn ($m) => [
                'role' => in_array($m['role'] ?? '', ['user', 'assistant'], true) ? $m['role'] : 'user',
                'content' => (string) ($m['content'] ?? ''),
            ], $history),
            [['role' => 'user', 'content' => $message]]
        );

        $reply = $this->ai->customChat($this->buildPrompt($caller), $messages);

        $payload = null;
        if (preg_match('/\[TIMESHEET_DATA\](\{.*?\})\[\/TIMESHEET_DATA\]/s', $reply, $m)) {
            $decoded = json_decode($m[1], true);
            if (is_array($decoded)) {
                $payload = $decoded;
                $reply = trim(str_replace($m[0], '', $reply));
            }
        }

        return ['reply' => $reply !== '' ? $reply : 'Sorry, I lost the thread. Could you repeat that?', 'payload' => $payload];
    }

    private function buildPrompt(User $caller): string
    {
        $isAdmin = $caller->role === Role::SLUG_ADMIN;
        $today = Carbon::now('Asia/Kolkata')->format('Y-m-d');
        $yesterday = Carbon::yesterday('Asia/Kolkata')->format('Y-m-d');

        $userScope = $isAdmin
            ? "You are logging on behalf of someone — ask whose timesheet this is (their full name as it appears in the team) UNLESS the caller already specified, or said 'for me'. The caller's name is {$caller->name}."
            : "You are helping {$caller->name} log their OWN timesheet. Do NOT ask whose timesheet this is — it is always {$caller->name}.";

        return "You are Tessa's Timesheet Assistant. Help the user log a single timesheet entry conversationally.

{$userScope}

Required fields:
- work_date (YYYY-MM-DD) — default to yesterday ({$yesterday}) if the user does not specify; today is {$today}.
- start_time (HH:MM, 24-hour)
- end_time (HH:MM, 24-hour)
- description (>= 10 chars, what they actually did)
- type (regular | overtime)

Rules:
- Ask ONE question per turn for whichever required field is missing. Be warm and concise.
- Infer values from the user's message when obvious (e.g. \"3 hours overtime last night\" implies type=overtime, work_date=yesterday).
- Overnight (end_time <= start_time) is ONLY valid when type=overtime — if you detect an overnight, confirm with the user before assuming.
- For weekend dates (Sat/Sun), the system will automatically classify all hours as overtime regardless of the type you record — but still ask the user for type=regular vs overtime so we record their intent.

When and ONLY when ALL FIVE required fields are known, append on its OWN line at the end of your reply, EXACTLY in this format:
[TIMESHEET_DATA]{\"target_user\":\"<full name or empty for self>\",\"work_date\":\"YYYY-MM-DD\",\"start_time\":\"HH:MM\",\"end_time\":\"HH:MM\",\"description\":\"...\",\"type\":\"regular|overtime\"}[/TIMESHEET_DATA]

Until ALL five are confirmed, NEVER emit the block. Always restate the values back to the user before emitting the block, like: \"Got it: 9pm to 1am yesterday, overtime, fixed login bug. Submit?\"";
    }
}
