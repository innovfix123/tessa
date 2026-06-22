<?php

namespace App\Console\Commands;

use App\Models\SlackInsight;
use App\Models\SlackInsightUserState;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * One-off cleanup for the huddle-suggestion duplication bug.
 *
 * Root cause (now fixed forward in SlackInsightsService::extractFromMeetingNote):
 * a single huddle note (one immutable Slack file_id) could be matched to TWO
 * different meetings across two sync runs — because the match depends on mutable
 * state (attendee slack_user_id mappings + scheduled-meeting availability). Every
 * dedup key was meeting-scoped, so the second run created a full DUPLICATE set of
 * suggestions under the second meeting_id, and any "Ignore" the user had applied
 * to the first set did NOT carry over → dismissed items reappeared.
 *
 * This command collapses such duplicates: per file_id spread across >1 meeting_id
 * it keeps ONE meeting's rows (preferring the real scheduled meeting over the
 * ad-hoc "huddle-…" one), carries any Ignore/Done state onto the survivors, and
 * deletes the redundant rows. Dry-run by default; pass --apply to commit.
 */
class DedupeSlackInsights extends Command
{
    protected $signature = 'slack:dedupe-insights
        {--apply : Commit the changes. Without this flag the command only reports what it would do.}';

    protected $description = 'Collapse duplicate huddle suggestions created when one note (file_id) was matched to >1 meeting. Keeps the scheduled-meeting copy, preserves Ignore/Done state, deletes the redundant set.';

    /** Statuses that mean "the user acted on this row" and must survive a merge. */
    private const RESOLVED = ['dismissed', 'actioned'];

    /** Minimum title similarity (%) to treat two rows as the same item (tolerates AI wording drift). */
    private const MATCH_THRESHOLD = 85.0;

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        // The fingerprint of the bug: one Slack note (source_message_ts) whose
        // rows are spread across more than one meeting_id. (COUNT(DISTINCT …)
        // ignores NULLs, so legacy meeting_id=NULL rows never trip this.)
        $fileIds = DB::table('slack_insights')
            ->whereNotNull('source_message_ts')
            ->select('source_message_ts')
            ->groupBy('source_message_ts')
            ->havingRaw('COUNT(DISTINCT meeting_id) > 1')
            ->pluck('source_message_ts');

        if ($fileIds->isEmpty()) {
            $this->info('No duplicated huddle notes found — nothing to do.');
            return self::SUCCESS;
        }

        $this->line(($apply ? 'APPLY' : 'DRY-RUN') . ' — ' . $fileIds->count() . ' duplicated note(s) found.');
        $this->newLine();

        $totalDeleted = 0;
        $totalMigrated = 0;

        foreach ($fileIds as $fileId) {
            $rows = SlackInsight::where('source_message_ts', $fileId)->orderBy('id')->get();

            $canonicalMid = $this->pickCanonicalMeetingId($rows);
            $canonical    = $rows->where('meeting_id', $canonicalMid)->values();
            $redundant    = $rows->where('meeting_id', '!=', $canonicalMid)->values();

            $this->line("file_id={$fileId}");
            $this->line("  keep   meeting_id={$canonicalMid}  ({$canonical->count()} rows: " . $canonical->pluck('id')->implode(',') . ')');
            $this->line('  remove meeting_id=' . $redundant->pluck('meeting_id')->unique()->implode(',') . "  ({$redundant->count()} rows: " . $redundant->pluck('id')->implode(',') . ')');

            // Plan state migrations: map each redundant row that carries user
            // action onto its counterpart in the canonical set.
            $migrations = []; // redundantId => canonicalId
            foreach ($redundant as $r) {
                $isShared   = $r->audience === 'meeting';
                $isResolved = in_array($r->status, self::RESOLVED, true);
                $isSnoozed  = $r->snooze_until !== null;

                // Personal rows with no user action carry nothing to preserve.
                if (! $isShared && ! $isResolved && ! $isSnoozed) continue;

                $match = $this->bestMatch($r, $canonical);
                if (! $match) {
                    if ($isResolved || $isSnoozed) {
                        $this->warn("    ! no canonical match for row {$r->id} (\"" . Str::limit($r->title, 40) . "\") — its '{$r->status}' state will be dropped");
                    }
                    continue;
                }
                $migrations[$r->id] = $match->id;
                $label = $isShared ? 'per-user state' : "'{$r->status}'";
                $this->line("    migrate {$label} from {$r->id} -> {$match->id} (\"" . Str::limit($match->title, 40) . '")');
            }

            if ($apply) {
                DB::transaction(function () use ($redundant, $canonical, $migrations, &$totalMigrated, &$totalDeleted) {
                    foreach ($redundant as $r) {
                        if (! isset($migrations[$r->id])) continue;
                        $canon = $canonical->firstWhere('id', $migrations[$r->id]);
                        if (! $canon) continue;

                        if ($r->audience === 'meeting') {
                            // Shared: state lives per-user — remap each onto the canonical insight.
                            foreach (SlackInsightUserState::where('insight_id', $r->id)->get() as $st) {
                                SlackInsightUserState::updateOrCreate(
                                    ['insight_id' => $canon->id, 'user_id' => $st->user_id],
                                    ['status' => $st->status, 'snooze_until' => $st->snooze_until, 'task_id' => $st->task_id]
                                );
                                $totalMigrated++;
                            }
                        } else {
                            // Personal: escalate the canonical row's status / snooze.
                            $changes = [];
                            if (in_array($r->status, self::RESOLVED, true) && ! in_array($canon->status, self::RESOLVED, true)) {
                                $changes['status'] = $r->status;
                            }
                            if ($r->snooze_until !== null && $canon->snooze_until === null) {
                                $changes['snooze_until'] = $r->snooze_until;
                            }
                            if ($changes) {
                                $canon->update($changes);
                                $totalMigrated++;
                            }
                        }
                    }

                    // Delete redundant rows and any per-user state they leave behind.
                    $redIds = $redundant->pluck('id')->all();
                    SlackInsightUserState::whereIn('insight_id', $redIds)->delete();
                    $totalDeleted += SlackInsight::whereIn('id', $redIds)->delete();
                });
            } else {
                $totalDeleted  += $redundant->count();
                $totalMigrated += count($migrations);
            }

            $this->newLine();
        }

        $this->info(($apply ? 'Deleted ' : 'Would delete ') . $totalDeleted . ' redundant row(s); '
            . ($apply ? 'migrated ' : 'would migrate ') . $totalMigrated . ' state(s).');
        if (! $apply) {
            $this->comment('Re-run with --apply to commit.');
        }

        return self::SUCCESS;
    }

    /**
     * Pick which meeting_id to keep for a file_id. Prefer a real scheduled
     * meeting (key not prefixed "huddle-"); tie → most rows, then the meeting
     * holding the newest row. If every copy is ad-hoc, keep the oldest row's.
     */
    private function pickCanonicalMeetingId($rows): string
    {
        $byMeeting = $rows->groupBy('meeting_id');
        $real = $byMeeting->keys()->filter(fn ($k) => ! Str::startsWith((string) $k, 'huddle-'));

        if ($real->isNotEmpty()) {
            return (string) $real
                ->sortByDesc(fn ($mid) => $byMeeting[$mid]->count() * 1_000_000_000 + (int) $byMeeting[$mid]->max('id'))
                ->first();
        }

        return (string) $rows->sortBy('id')->first()->meeting_id;
    }

    /**
     * Best canonical counterpart for a redundant row: same type, highest title
     * similarity at or above the threshold. Tolerates AI re-wording between runs
     * (e.g. "…continue current diet plan" vs "…continue diet plan").
     */
    private function bestMatch($redundant, $canonical): ?SlackInsight
    {
        $best = null;
        $bestPct = 0.0;
        foreach ($canonical as $c) {
            if ($c->type !== $redundant->type) continue;
            $pct = 0.0;
            similar_text(Str::lower((string) $redundant->title), Str::lower((string) $c->title), $pct);
            if ($pct > $bestPct) {
                $bestPct = $pct;
                $best = $c;
            }
        }

        return $bestPct >= self::MATCH_THRESHOLD ? $best : null;
    }
}
