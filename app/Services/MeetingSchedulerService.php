<?php

namespace App\Services;

use App\Models\Meeting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MeetingSchedulerService
{
    private ?TessaAIService $ai;

    public function __construct(?TessaAIService $ai = null)
    {
        $this->ai = $ai;
    }

    /**
     * Parse a natural language meeting request using AI.
     */
    public function parseRequest(string $message): array
    {
        if (! $this->ai) {
            return ['error' => 'AI service not available. Please use the structured form instead.'];
        }

        $today = Carbon::now('Asia/Kolkata');

        $systemPrompt = <<<PROMPT
You extract meeting scheduling details from natural language requests.
Today is {$today->format('Y-m-d')} ({$today->format('l')}).

Return ONLY valid JSON:
{
  "attendees": ["First name or full name of each person mentioned"],
  "date": "YYYY-MM-DD (resolve 'tomorrow', 'Monday', 'next week' etc.)",
  "preferred_time": "HH:MM in 24h format or null if not specified",
  "duration_minutes": 30,
  "title": "Short meeting title (max 8 words) inferred from context, or 'Team Meeting'",
  "recurrence": "none"
}

Rules:
- Extract all person names mentioned
- If no date specified, use tomorrow (next weekday if today is Friday)
- If no time specified, set preferred_time to null
- Default duration is 30 minutes unless specified
- No markdown, no explanation, ONLY JSON
PROMPT;

        $content = $this->ai->quickAi($systemPrompt, $message);

        // Clean markdown wrapping
        $content = preg_replace('/^```(?:json)?\s*/i', '', trim($content));
        $content = preg_replace('/\s*```\s*$/', '', $content);

        $parsed = json_decode($content, true);

        if (! is_array($parsed) || empty($parsed['attendees'])) {
            return ['error' => 'Could not understand the request. Please mention who you want to meet and when.'];
        }

        return $parsed;
    }

    /**
     * Resolve attendee names to user records using fuzzy matching.
     */
    public function resolveAttendees(array $names): array
    {
        $users = User::where('is_active', true)->get(['id', 'name']);
        $resolved = [];
        $unresolved = [];

        foreach ($names as $name) {
            $name = trim($name);
            if (empty($name)) continue;

            $match = null;
            $nameLower = strtolower($name);

            // Exact match
            $match = $users->first(fn ($u) => strtolower($u->name) === $nameLower);

            // First name match
            if (! $match) {
                $match = $users->first(fn ($u) => strtolower(explode(' ', $u->name)[0]) === $nameLower);
            }

            // Contains match
            if (! $match) {
                $match = $users->first(fn ($u) => str_contains(strtolower($u->name), $nameLower));
            }

            if ($match) {
                $resolved[] = ['id' => $match->id, 'name' => $match->name];
            } else {
                $unresolved[] = $name;
            }
        }

        return ['resolved' => $resolved, 'unresolved' => $unresolved];
    }

    /**
     * Get availability for a list of users on a specific date.
     * Returns busy blocks per user + a merged timeline.
     */
    public function getAvailability(array $userIds, string $date): array
    {
        $targetDate = Carbon::parse($date, 'Asia/Kolkata');
        $dayOfWeek  = $targetDate->format('l'); // Monday, Tuesday, etc.
        $isWeekday  = ! $targetDate->isWeekend();

        // Get all meetings where any of these users are involved
        $meetings = Meeting::query()
            ->where(function ($q) use ($userIds) {
                $q->whereIn('owner_id', $userIds);
                foreach ($userIds as $uid) {
                    $q->orWhereJsonContains('attendees', $uid);
                }
            })
            ->get();

        // Get skip dates
        $skipKeys = DB::table('meeting_skips')
            ->where('skip_date', $targetDate->toDateString())
            ->pluck('meeting_key')
            ->toArray();

        // Expand meetings to check if they occur on this date
        $userBusy = [];
        foreach ($userIds as $uid) {
            $userBusy[$uid] = [];
        }

        foreach ($meetings as $meeting) {
            // Skip if this meeting is skipped on this date
            if (in_array($meeting->meeting_key, $skipKeys)) continue;

            $occursOnDate = false;

            if ($meeting->recurrence === 'daily_weekdays' && $isWeekday) {
                $occursOnDate = true;
            } elseif ($meeting->recurrence === 'tue_to_fri' && $isWeekday && $dayOfWeek !== 'Monday') {
                $occursOnDate = true;
            } elseif ($meeting->recurrence === 'mon_thu' && in_array($dayOfWeek, ['Monday', 'Thursday'], true)) {
                $occursOnDate = true;
            } elseif ($meeting->recurrence === 'mon_wed_fri' && in_array($dayOfWeek, ['Monday', 'Wednesday', 'Friday'], true)) {
                $occursOnDate = true;
            } elseif ($meeting->recurrence === 'weekly' && $meeting->day_of_week === $dayOfWeek) {
                $occursOnDate = true;
            } elseif ($meeting->recurrence === 'monthly_first' && $meeting->day_of_week === $dayOfWeek && $targetDate->day <= 7) {
                // First <day_of_week> of the month only (first occurrence ⇒ day 1–7).
                $occursOnDate = true;
            } elseif ($meeting->recurrence === 'none') {
                // One-time meeting: occurs ONLY on its actual stored date. Without
                // this, a one-off booked for (say) this Friday would resurface as a
                // busy block on every future Friday. Legacy rows that have no stored
                // date are ignored on future days rather than recurring forever.
                $occursOnDate = $meeting->meeting_date
                    && $meeting->meeting_date->toDateString() === $targetDate->toDateString();
            }

            if (! $occursOnDate) continue;

            // Parse meeting time to minutes from midnight
            $startMin = $this->timeToMinutes($meeting->time);
            if ($startMin === null) continue;

            $endMin = $startMin + 30; // Assume 30-min meetings

            $block = [
                'title'       => $meeting->title,
                'meeting_key' => $meeting->meeting_key,
                'meeting_id'  => $meeting->id,
                'start'       => $startMin,
                'end'         => $endMin,
                'time'        => $meeting->time,
            ];

            // Check which of our target users are in this meeting
            $meetingAttendees = $meeting->attendees ?? [];
            foreach ($userIds as $uid) {
                if ($uid == $meeting->owner_id || in_array($uid, $meetingAttendees)) {
                    $userBusy[$uid][] = $block;
                }
            }
        }

        // Also check Google Calendar for users who have it connected
        foreach ($userIds as $uid) {
            $gUser = User::find($uid);
            if ($gUser && $gUser->hasGoogleConnection()) {
                try {
                    $gcal   = GoogleUserService::forUser($gUser);
                    $events = $gcal->getEventsForDate($date);
                    foreach ($events as $ev) {
                        if ($ev['start_minutes'] === null || $ev['end_minutes'] === null) continue;
                        if ($ev['status'] === 'cancelled') continue;

                        $userBusy[$uid][] = [
                            'title'       => $ev['title'] . ' (Google)',
                            'meeting_key' => 'gcal-' . ($ev['id'] ?? ''),
                            'meeting_id'  => null,
                            'start'       => $ev['start_minutes'],
                            'end'         => $ev['end_minutes'],
                            'time'        => $ev['start'] ?? '',
                        ];
                    }
                } catch (\Throwable $e) {
                    Log::debug('MeetingScheduler: Google Calendar check failed', ['user' => $uid, 'error' => $e->getMessage()]);
                }
            }
        }

        // Sort busy blocks by start time
        foreach ($userBusy as $uid => &$blocks) {
            usort($blocks, fn ($a, $b) => $a['start'] - $b['start']);
        }

        return [
            'date'       => $targetDate->toDateString(),
            'day'        => $dayOfWeek,
            'user_busy'  => $userBusy,
        ];
    }

    /**
     * Find free time slots for all users.
     */
    public function findFreeSlots(array $availability, array $userIds, int $durationMins = 30, ?string $preferredTime = null): array
    {
        $workStart = 540;  // 9:00 AM
        $workEnd   = 1140; // 7:00 PM
        $step      = 30;   // 30-min increments
        $userBusy  = $availability['user_busy'] ?? [];

        // On today's date (IST), any slot starting before "now" has already passed and must not be offered.
        $isToday = (($availability['date'] ?? null) === Carbon::today('Asia/Kolkata')->toDateString());
        $nowIst  = Carbon::now('Asia/Kolkata');
        $nowMin  = $isToday ? ($nowIst->hour * 60 + $nowIst->minute) : -1;

        // Merge all busy blocks across all users
        $allBusy = [];
        foreach ($userIds as $uid) {
            foreach ($userBusy[$uid] ?? [] as $block) {
                $allBusy[] = $block;
            }
        }

        // For each 30-min slot, check if anyone is busy
        $slots = [];
        for ($t = $workStart; $t + $durationMins <= $workEnd; $t += $step) {
            $slotEnd = $t + $durationMins;
            $passed  = $isToday && $t < $nowMin;

            $clashes = [];
            $clashUsers = [];

            foreach ($userIds as $uid) {
                foreach ($userBusy[$uid] ?? [] as $block) {
                    // Check overlap
                    if ($block['start'] < $slotEnd && $block['end'] > $t) {
                        $clashes[] = $block['title'];
                        $userName = User::find($uid)?->name ?? "User {$uid}";
                        $clashUsers[] = $userName;
                    }
                }
            }

            $slots[] = [
                'start_minutes' => $t,
                'end_minutes'   => $slotEnd,
                'time'          => $this->minutesToTime($t),
                'end_time'      => $this->minutesToTime($slotEnd),
                'available'     => empty($clashes),
                'passed'        => $passed,
                'clash_count'   => count(array_unique($clashUsers)),
                'clashes'       => array_unique($clashes),
                'clash_users'   => array_unique($clashUsers),
            ];
        }

        // Sort: available first, then by proximity to preferred time
        $preferredMin = $preferredTime ? $this->timeToMinutes($preferredTime) : null;

        usort($slots, function ($a, $b) use ($preferredMin) {
            // Passed (already-elapsed) slots always sink to the bottom
            if (($a['passed'] ?? false) !== ($b['passed'] ?? false)) {
                return ($a['passed'] ?? false) ? 1 : -1;
            }
            // Available slots first
            if ($a['available'] !== $b['available']) {
                return $a['available'] ? -1 : 1;
            }
            // Then by clash count (fewer clashes better)
            if ($a['clash_count'] !== $b['clash_count']) {
                return $a['clash_count'] - $b['clash_count'];
            }
            // Then by proximity to preferred time
            if ($preferredMin !== null) {
                $distA = abs($a['start_minutes'] - $preferredMin);
                $distB = abs($b['start_minutes'] - $preferredMin);
                return $distA - $distB;
            }
            return $a['start_minutes'] - $b['start_minutes'];
        });

        return $slots;
    }

    /**
     * Suggest a new time for a clashing meeting on the same day.
     * Finds the best slot that works for ALL of that meeting's attendees.
     */
    public function suggestReschedule(string $meetingKey, string $date): array
    {
        $meeting = Meeting::where('meeting_key', $meetingKey)->first();
        if (! $meeting) {
            return ['error' => 'Meeting not found'];
        }

        // Get all attendees of this meeting
        $attendees = $meeting->attendees ?? [];
        if ($meeting->owner_id && ! in_array($meeting->owner_id, $attendees)) {
            $attendees[] = $meeting->owner_id;
        }

        if (empty($attendees)) {
            return ['error' => 'No attendees found'];
        }

        // Get availability for all attendees on that date
        $availability = $this->getAvailability($attendees, $date);

        // Find free slots excluding the meeting we're rescheduling
        $workStart = 540;
        $workEnd   = 1140;
        $step      = 30;
        $duration  = 30;
        $userBusy  = $availability['user_busy'] ?? [];
        $originalStart = $this->timeToMinutes($meeting->time);

        $suggestions = [];
        for ($t = $workStart; $t + $duration <= $workEnd; $t += $step) {
            $slotEnd = $t + $duration;
            $hasClash = false;

            foreach ($attendees as $uid) {
                foreach ($userBusy[$uid] ?? [] as $block) {
                    // Skip the meeting we're rescheduling itself
                    if (($block['meeting_key'] ?? '') === $meetingKey) continue;

                    if ($block['start'] < $slotEnd && $block['end'] > $t) {
                        $hasClash = true;
                        break;
                    }
                }
                if ($hasClash) break;
            }

            if (! $hasClash) {
                $suggestions[] = [
                    'time'     => $this->minutesToTime($t),
                    'end_time' => $this->minutesToTime($slotEnd),
                    'distance' => $originalStart !== null ? abs($t - $originalStart) : $t,
                ];
            }
        }

        // Sort by proximity to original time
        usort($suggestions, fn ($a, $b) => $a['distance'] - $b['distance']);

        return [
            'meeting_key'  => $meetingKey,
            'title'        => $meeting->title,
            'original_time' => $meeting->time,
            'suggestions'  => array_slice($suggestions, 0, 3),
        ];
    }

    /**
     * Convert time string ("02:30 PM" or "14:30") to minutes from midnight.
     */
    private function timeToMinutes(?string $time): ?int
    {
        if (! $time) return null;

        $time = trim($time);

        // Handle 24h format (HH:MM)
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $time, $m)) {
            return (int) $m[1] * 60 + (int) $m[2];
        }

        // Handle 12h format (02:30 PM)
        if (preg_match('/^(\d{1,2}):(\d{2})\s*(AM|PM)$/i', $time, $m)) {
            $h = (int) $m[1];
            $min = (int) $m[2];
            $period = strtoupper($m[3]);

            if ($period === 'PM' && $h !== 12) $h += 12;
            if ($period === 'AM' && $h === 12) $h = 0;

            return $h * 60 + $min;
        }

        return null;
    }

    /**
     * Convert minutes from midnight to 12h time string.
     */
    private function minutesToTime(int $minutes): string
    {
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;
        $period = $h >= 12 ? 'PM' : 'AM';
        $h12 = $h % 12;
        if ($h12 === 0) $h12 = 12;

        return sprintf('%d:%02d %s', $h12, $m, $period);
    }
}
