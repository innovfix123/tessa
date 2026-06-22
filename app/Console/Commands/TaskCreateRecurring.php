<?php

namespace App\Console\Commands;

use App\Models\TaskRecurrence;
use App\Services\TessaTaskService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class TaskCreateRecurring extends Command
{
    protected $signature = 'tasks:create-recurring';

    protected $description = 'Create tasks from active recurring task definitions';

    public function handle(): int
    {
        $now = Carbon::now('Asia/Kolkata');

        $recurrences = TaskRecurrence::where('is_active', true)
            ->where('next_run_at', '<=', $now)
            ->with(['assignedBy', 'assignedTo'])
            ->get();

        $service = app(TessaTaskService::class);
        $created = 0;

        foreach ($recurrences as $recurrence) {
            if (! $recurrence->assignedBy || ! $recurrence->assignedTo) {
                Log::warning('TaskCreateRecurring: skipping, missing user', ['id' => $recurrence->id]);

                continue;
            }

            try {
                $deadline = $now->copy()->addHours($recurrence->deadline_offset_hours);

                $service->createAndNotify(
                    $recurrence->assignedBy,
                    $recurrence->assigned_to,
                    $recurrence->title,
                    $recurrence->description,
                    $recurrence->priority,
                    $deadline->toDateTimeString()
                );

                $nextRun = $this->calculateNextRun($recurrence, $now);
                $recurrence->update(['next_run_at' => $nextRun]);

                $created++;
                Log::info('TaskCreateRecurring: created', ['recurrence_id' => $recurrence->id, 'next' => $nextRun]);
            } catch (\Throwable $e) {
                Log::error('TaskCreateRecurring: failed', ['id' => $recurrence->id, 'error' => $e->getMessage()]);
            }
        }

        $this->info("Created {$created} recurring task(s).");

        return 0;
    }

    private function calculateNextRun(TaskRecurrence $r, Carbon $now): Carbon
    {
        if ($r->recurrence_type === 'daily') {
            return $now->copy()->addDay()->startOfDay()->addHours(8)->utc();
        }

        if ($r->recurrence_type === 'weekly') {
            $target = $r->recurrence_day ?? 1;

            return $now->copy()->next($target)->startOfDay()->addHours(8)->utc();
        }

        if ($r->recurrence_type === 'monthly') {
            $target = $r->recurrence_day ?? 1;

            return $now->copy()->addMonth()->startOfMonth()->addDays($target - 1)->startOfDay()->addHours(8)->utc();
        }

        return $now->copy()->addDay()->startOfDay()->addHours(8)->utc();
    }
}
