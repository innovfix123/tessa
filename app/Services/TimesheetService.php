<?php

namespace App\Services;

use App\Models\Timesheet;
use App\Models\TimeSlot;
use App\Models\User;
use App\Models\WorkforcePayment;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TimesheetService
{
    public const MIN_DESC_WEB = 50;
    public const MIN_DESC_AI = 10;
    public const REGULAR_CAP_WEEKDAY_HOURS = 8.0;

    public function __construct(private SlackService $slackService) {}

    /**
     * Create or update a timesheet for the given user/date.
     *
     * @param  array<int, array{start_time: string, end_time: string, type?: string, description: string}>  $slots
     */
    public function createOrUpdate(User $user, string $workDate, array $slots, bool $isAdmin, string $source = 'web'): Timesheet
    {
        $minDesc = $source === 'ai' ? self::MIN_DESC_AI : self::MIN_DESC_WEB;

        $date = Carbon::parse($workDate, 'Asia/Kolkata');
        if ($date->gt(Carbon::today('Asia/Kolkata'))) {
            throw new \InvalidArgumentException('Cannot log timesheet for a future date.');
        }

        if (empty($slots)) {
            throw new \InvalidArgumentException('At least one time slot is required.');
        }

        $weekStart = $date->copy()->startOfWeek(Carbon::MONDAY);

        if (! $isAdmin) {
            $this->assertWeekUnlocked($user->id, $weekStart);
        }

        $normalized = [];
        foreach ($slots as $s) {
            $description = trim((string) ($s['description'] ?? ''));
            if (mb_strlen($description) < $minDesc) {
                throw new \InvalidArgumentException("Description must be at least {$minDesc} characters.");
            }

            $start = Carbon::parse($s['start_time']);
            $end = Carbon::parse($s['end_time']);
            $isOvernight = $end->lte($start);
            $type = $s['type'] ?? 'regular';

            if (! in_array($type, ['regular', 'overtime'], true)) {
                throw new \InvalidArgumentException('Slot type must be regular or overtime.');
            }

            if ($isOvernight && $type !== 'overtime') {
                throw new \InvalidArgumentException('Overnight slots are only allowed when type=overtime.');
            }

            $duration = $isOvernight
                ? abs($start->diffInMinutes($end->copy()->addDay())) / 60.0
                : abs($start->diffInMinutes($end)) / 60.0;

            if ($duration <= 0) {
                throw new \InvalidArgumentException('Slot duration must be positive.');
            }

            if (! $isAdmin && $type === 'overtime' && (float) $user->hourly_rate <= 0) {
                // Allow logging — amount will be ₹0 — but warn in log.
                Log::info('TimesheetService: overtime slot with hourly_rate<=0', [
                    'user_id' => $user->id,
                    'rate' => $user->hourly_rate,
                ]);
            }

            $normalized[] = [
                'start_time' => $start->format('H:i:s'),
                'end_time' => $end->format('H:i:s'),
                'type' => $type,
                'description' => $description,
                'duration' => $duration,
            ];
        }

        return DB::transaction(function () use ($user, $date, $weekStart, $normalized) {
            $sheet = Timesheet::updateOrCreate(
                ['user_id' => $user->id, 'work_date' => $date->format('Y-m-d')],
                [
                    'week_start' => $weekStart->format('Y-m-d'),
                    'hourly_rate_snapshot' => (float) $user->hourly_rate,
                ]
            );

            $sheet->timeSlots()->delete();
            foreach ($normalized as $n) {
                $sheet->timeSlots()->create([
                    'start_time' => $n['start_time'],
                    'end_time' => $n['end_time'],
                    'type' => $n['type'],
                    'description' => $n['description'],
                    'duration_hours' => round($n['duration'], 2),
                ]);
            }

            $this->recomputeTotals($sheet, $date);

            Log::info('TimesheetService: timesheet saved', [
                'timesheet_id' => $sheet->id,
                'user_id' => $user->id,
                'date' => $date->format('Y-m-d'),
                'total_hours' => $sheet->fresh()->total_hours,
                'amount' => $sheet->fresh()->amount,
            ]);

            return $sheet->fresh('timeSlots');
        });
    }

    private function recomputeTotals(Timesheet $sheet, Carbon $date): void
    {
        $slots = $sheet->timeSlots()->get();
        $regularSlotHours = (float) $slots->where('type', 'regular')->sum('duration_hours');
        $overtimeSlotHours = (float) $slots->where('type', 'overtime')->sum('duration_hours');
        $total = $regularSlotHours + $overtimeSlotHours;

        if ($date->isWeekend()) {
            $regular = 0.0;
            $overtime = $total;
        } else {
            $regular = min(self::REGULAR_CAP_WEEKDAY_HOURS, $regularSlotHours);
            $excessRegular = max(0.0, $regularSlotHours - self::REGULAR_CAP_WEEKDAY_HOURS);
            $overtime = $overtimeSlotHours + $excessRegular;
        }

        $sheet->update([
            'total_hours' => round($total, 2),
            'regular_hours' => round($regular, 2),
            'overtime_hours' => round($overtime, 2),
            'amount' => round($overtime * (float) $sheet->hourly_rate_snapshot, 2),
        ]);
    }

    public function assertWeekUnlocked(int $userId, Carbon $weekStart): void
    {
        $exists = WorkforcePayment::where('user_id', $userId)
            ->where('week_start', $weekStart->format('Y-m-d'))
            ->where('status', 'paid')
            ->exists();

        if ($exists) {
            throw new \InvalidArgumentException('This week is paid and locked.');
        }
    }

    public function delete(User $user, Timesheet $sheet, bool $isAdmin): void
    {
        if ($sheet->user_id !== $user->id && ! $isAdmin) {
            throw new \InvalidArgumentException('You can only delete your own timesheets.');
        }
        if (! $isAdmin) {
            $this->assertWeekUnlocked($user->id, Carbon::parse($sheet->week_start));
        }
        $sheet->delete();
    }

    public static function mondayOf(string|Carbon $date): Carbon
    {
        return Carbon::parse($date)->startOfWeek(Carbon::MONDAY);
    }
}
