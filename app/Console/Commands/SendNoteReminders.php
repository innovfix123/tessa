<?php

namespace App\Console\Commands;

use App\Models\DashboardNote;
use App\Services\SlackService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendNoteReminders extends Command
{
    protected $signature = 'notes:send-reminders';

    protected $description = 'Send Slack DM reminders for dashboard notes with unchecked items';

    /**
     * How often a monthly reminder re-nudges through its due day, until the
     * owner checks everything off. The Slack quiet window (10pm-9am IST)
     * still suppresses out-of-hours sends, so in practice this nudges a few
     * times across working hours rather than overnight.
     */
    private const MONTHLY_NUDGE_MINUTES = 120;

    public function handle(): int
    {
        $now = now();
        $isWeekend = Carbon::now('Asia/Kolkata')->isWeekend();

        $notes = DashboardNote::with('user')
            ->where(function ($q) {
                $q->whereNotNull('reminder_at')
                    ->orWhereNotNull('reminder_day')
                    ->orWhere(function ($q2) {
                        $q2->whereNotNull('reminder_interval')->where('reminder_interval', '>', 0);
                    });
            })
            ->get();

        if ($notes->isEmpty()) {
            return self::SUCCESS;
        }

        $slack = new SlackService;

        // Bundle by note owner so each user gets one DM listing all their due notes.
        $buckets = [];

        foreach ($notes as $note) {
            if ($note->reminder_day) {
                // ---- Monthly day-of-month reminder ----
                $istNow = Carbon::now('Asia/Kolkata');
                if (! $note->isMonthlyDueOn($istNow)) {
                    continue; // not its day this month
                }

                // Start each monthly occurrence with a fresh (all-unchecked)
                // checklist, exactly once per due day. Persisted up front so a
                // DM deferred by the quiet window doesn't leave last month's
                // ticks showing, and so the dashboard reads it fresh too.
                $todayKey = $istNow->toDateString();
                if (optional($note->monthly_reset_on)->toDateString() !== $todayKey) {
                    $note->items = collect($note->items ?? [])->map(fn ($i) => [
                        'text' => $i['text'],
                        'checked' => false,
                    ])->values()->toArray();
                    $note->monthly_reset_on = $todayKey;
                    $note->save();
                }

                $unchecked = collect($note->items)->filter(fn ($i) => ! ($i['checked'] ?? false));
                if ($unchecked->isEmpty()) {
                    continue; // owner already acted on it today
                }

                // Nudge repeatedly through the day until acted on.
                if ($note->last_reminded_at) {
                    $due = $note->last_reminded_at->copy()->addMinutes(self::MONTHLY_NUDGE_MINUTES);
                    if ($now->lt($due)) {
                        continue;
                    }
                }
            } else {
                $unchecked = collect($note->items)->filter(fn ($i) => ! ($i['checked'] ?? false));
                if ($unchecked->isEmpty()) {
                    continue;
                }

                if ($note->reminder_at) {
                    if ($now->lt($note->reminder_at)) {
                        continue;
                    }
                    if ($note->last_reminded_at && $note->last_reminded_at->gte($note->reminder_at)) {
                        continue;
                    }
                } else {
                    if ($isWeekend) {
                        continue;
                    }
                    if ($note->last_reminded_at) {
                        $due = $note->last_reminded_at->copy()->addMinutes($note->reminder_interval);
                        if ($now->lt($due)) {
                            continue;
                        }
                    }
                }
            }

            if (! $note->user) {
                continue;
            }

            $userId = $note->user->id;
            if (! isset($buckets[$userId])) {
                $buckets[$userId] = [
                    'name' => $note->user->name,
                    'sections' => [],
                    'note_ids' => [],
                    'total_items' => 0,
                ];
            }

            $title = trim((string) ($note->title ?? '')) !== '' ? $note->title : 'Note';
            $itemList = $unchecked->map(fn ($i) => '• '.$i['text'])->implode("\n");
            $buckets[$userId]['sections'][] = "*{$title}*\n{$itemList}";
            $buckets[$userId]['note_ids'][] = $note->id;
            $buckets[$userId]['total_items'] += $unchecked->count();
        }

        $sent = 0;
        foreach ($buckets as $bucket) {
            $slackId = $slack->getUserIdByName($bucket['name']);
            if (! $slackId) {
                continue;
            }

            $listCount = count($bucket['sections']);
            $itemCount = $bucket['total_items'];
            $header = $listCount === 1
                ? "You have {$itemCount} pending item(s):"
                : "You have {$itemCount} pending item(s) across {$listCount} list(s):";

            $message = ":bell: *Reminder*\n\n"
                .$header."\n\n"
                .implode("\n\n", $bucket['sections'])
                ."\n\n_Check them off in Tessa to stop ".($listCount === 1 ? 'this reminder' : 'these reminders').'._';

            if ($slack->sendDirectMessage($slackId, $message)) {
                DashboardNote::whereIn('id', $bucket['note_ids'])->update(['last_reminded_at' => $now]);
                $sent++;
                Log::info('NoteReminder sent', [
                    'user' => $bucket['name'],
                    'note_ids' => $bucket['note_ids'],
                    'item_count' => $itemCount,
                ]);
            }
        }

        if ($sent) {
            $this->info("Sent {$sent} note reminder DM(s).");
        }

        return self::SUCCESS;
    }
}
