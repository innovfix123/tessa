<?php

namespace App\Console\Commands;

use App\Models\Meeting;
use App\Models\SlackInsight;
use App\Models\User;
use App\Services\SlackHuddleSyncService;
use App\Services\SlackUserService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * One-off cleanup for the huddle-suggestion ATTENDEE over-matching bug.
 *
 * The old SlackHuddleSyncService inferred attendees by scanning the raw huddle
 * transcript/Canvas body for names + <@U…> mentions, so anyone merely *mentioned*
 * in conversation got recorded as an attendee — inflating meeting_attendee_ids and
 * audience_user_ids, leaking suggestions to people who weren't in the huddle and
 * mislabelling 1:1s as groups (and vice-versa).
 *
 * Core rule enforced: a user must NEVER see a huddle suggestion unless they were
 * genuinely in it. This command re-scopes existing rows to their real attendees
 * using, in order of trust:
 *   1. The Slack AI-notes "Attendees" section, re-fetched by file_id
 *      (SlackHuddleSyncService::parseAttendeesSection). Authoritative → full
 *      re-scope (targeted action items go to [doer, doer's manager]; shared/
 *      unassigned go to all attendees).
 *   2. Structural ground truth that needs NO Canvas, applied ONLY subtractively
 *      (remove wrong people, never add):
 *        - a SCHEDULED meeting's real roster (meetings table), or
 *        - a DM huddle (source_channel_name is a bare Slack id ⇒ a 1:1): pruned to
 *          the confirmed pair {DM counterpart, assignee, assigner} — but only when
 *          its stored audience is over-matched (>2), so genuine 2-person 1:1s are
 *          left untouched.
 * Rows with no Canvas and no structural signal are left untouched (never narrowed
 * on a guess). Nothing is ever deleted; per-user dismiss/snooze/task state
 * (slack_insight_user_state) is preserved so cleared items stay in Archives → Slack
 * for retained attendees. Dry-run by default; --apply to commit. Re-fetching from
 * Slack is read-only (files.info) — no DMs are sent.
 */
class RescopeSlackInsights extends Command
{
    protected $signature = 'slack:rescope-insights
        {--apply : Commit the changes. Without this flag the command only reports what it would do.}
        {--for-user= : Re-fetch as this Slack-connected user id (DM-huddle Canvases only resolve in their own Slack view).}
        {--file= : Restrict to a single source_message_ts (file_id) — for debugging.}';

    protected $description = 'Re-scope over-matched huddle suggestions to their real attendees (AI-notes Attendees section, else scheduled-meeting/DM structural ground truth). Never deletes, never adds unconfirmed people. Dry-run by default; --apply to commit.';

    public function handle(SlackHuddleSyncService $svc): int
    {
        $apply = (bool) $this->option('apply');

        $caller = $this->resolveCaller();
        if (! $caller) {
            $this->error('No Slack-connected user found — cannot re-fetch huddle notes.');
            return self::FAILURE;
        }
        try {
            $slack = SlackUserService::forUser($caller);
        } catch (\Throwable $e) {
            $this->error('Could not build a Slack client for '.$caller->name.': '.$e->getMessage());
            return self::FAILURE;
        }

        $users     = User::where('is_active', true)->get(['id', 'name', 'slack_user_id', 'reporting_manager_id']);
        $usersById = $users->keyBy('id');

        $rows = SlackInsight::query()
            ->where('audience', 'meeting')
            ->whereNotNull('source_message_ts')
            ->when($this->option('file'), fn ($q, $f) => $q->where('source_message_ts', $f))
            ->orderBy('id')
            ->get();

        $byFile = $rows->groupBy('source_message_ts');
        if ($byFile->isEmpty()) {
            $this->info('No huddle insight rows to re-scope.');
            return self::SUCCESS;
        }

        $this->line(($apply ? 'APPLY' : 'DRY-RUN').' — '.$byFile->count().' Canvas file(s) covering '
            .$rows->count().' row(s). Re-fetching as '.$caller->name.'.');
        $this->newLine();

        $norm = fn ($a) => collect(is_array($a) ? $a : [])
            ->map(fn ($x) => (int) $x)->filter()->unique()->sort()->values()->all();

        $canvasRows = $structuralRows = $demotions = $filesTouched = $filesSkipped = 0;

        foreach ($byFile as $fileId => $group) {
            $fileId = (string) $fileId;

            // 1. Authoritative source: the AI-notes Attendees section (if readable).
            $canvas = $this->tryCanvas($svc, $slack, $fileId, $users); // ['ids','count'] | null

            $fileUpdates = [];
            $fileLogs    = [];

            foreach ($group as $row) {
                if ($canvas !== null) {
                    $mode = 'canvas';
                    $auth = $canvas['ids'];
                    $count = $canvas['count'];
                } else {
                    // 2. Structural fallback (no Canvas) — scheduled roster / DM pair.
                    $st = $this->structuralAttendees($row);
                    if ($st === null) {
                        continue; // no signal → leave untouched
                    }
                    $mode = 'structural:'.$st['source'];
                    $auth = $st['ids'];
                    $count = count($st['ids']);
                }

                $newRoster = $norm($auth);
                if (empty($newRoster)) {
                    continue;
                }

                $newKind     = $this->kindFor($row, $count, $mode);
                $assignee    = $row->suggested_assignee_id ? (int) $row->suggested_assignee_id : null;
                $existingAud = $norm($row->audience_user_ids);
                $demoted     = false;

                if ($mode === 'canvas') {
                    // Verified attendees → may add the doer's manager / fan out to all.
                    if ($assignee !== null && in_array($assignee, $newRoster, true)) {
                        $mgr         = optional($usersById->get($assignee))->reporting_manager_id;
                        $viewers     = $norm([$assignee, $mgr ? (int) $mgr : null]);
                        $newAssignee = $assignee;
                    } elseif ($assignee !== null) {
                        $viewers     = $newRoster;
                        $newAssignee = null;
                        $demoted     = true;
                    } else {
                        $viewers     = $newRoster;
                        $newAssignee = null;
                    }
                } else {
                    // Unverified structural fallback → SUBTRACTIVE only: keep only the
                    // existing viewers who are in the confirmed set; never add anyone.
                    $viewers     = $norm(array_intersect($existingAud, $newRoster));
                    $newAssignee = ($assignee !== null && in_array($assignee, $newRoster, true)) ? $assignee : null;
                    if ($assignee !== null && $newAssignee === null) {
                        $demoted = true;
                    }
                }

                $added   = array_values(array_diff($viewers, $existingAud));
                $removed = array_values(array_diff($existingAud, $viewers));

                $unchanged = $norm($row->meeting_attendee_ids) === $newRoster
                    && $existingAud === $viewers
                    && (string) $row->meeting_kind === (string) $newKind
                    && (int) $assignee === (int) $newAssignee;
                if ($unchanged) {
                    continue;
                }

                if ($demoted) {
                    $demotions++;
                }
                $mode === 'canvas' ? $canvasRows++ : $structuralRows++;

                $fileLogs[] = "  row {$row->id} [{$row->type}] ({$mode})"
                    .($demoted ? "  DEMOTE assignee {$assignee}->shared" : '')
                    .((string) $row->meeting_kind !== (string) $newKind ? "  kind {$row->meeting_kind}->{$newKind}" : '')
                    .'  viewers +['.implode(',', $added).'] -['.implode(',', $removed).']';

                $fileUpdates[] = [$row, [
                    'meeting_attendee_ids'  => $newRoster,
                    'meeting_kind'          => $newKind,
                    'audience_user_ids'     => $viewers,
                    'suggested_assignee_id' => $newAssignee,
                ]];
            }

            if ($fileUpdates) {
                $this->line("file_id={$fileId}".($canvas !== null
                    ? '  canvas attendees=['.implode(',', $canvas['ids']).']'
                    : '  (canvas unavailable — structural fallback)'));
                foreach ($fileLogs as $l) {
                    $this->line($l);
                }
                if ($apply) {
                    DB::transaction(function () use ($fileUpdates) {
                        foreach ($fileUpdates as [$row, $changes]) {
                            $row->update($changes);
                        }
                    });
                }
                $filesTouched++;
                $this->newLine();
            } else {
                $this->warn("file_id={$fileId} — not re-scopable (no readable Canvas, no structural signal, or already correct); SKIP ({$group->count()} row(s) untouched)");
                $filesSkipped++;
            }
        }

        $this->info(($apply ? 'Re-scoped ' : 'Would re-scope ').($canvasRows + $structuralRows)
            ." row(s) ({$canvasRows} canvas, {$structuralRows} structural) across {$filesTouched} file(s); "
            ."{$demotions} demotion(s); {$filesSkipped} file(s) skipped.");
        if (! $apply) {
            $this->comment('Re-run with --apply to commit. (Re-fetch is read-only; no Slack DMs are sent.)');
        }

        return self::SUCCESS;
    }

    /** Re-fetch + parse the AI-notes Attendees section. Null if not retrievable/resolvable. */
    private function tryCanvas(SlackHuddleSyncService $svc, SlackUserService $slack, string $fileId, $users): ?array
    {
        if (preg_match('/^[a-f0-9]{32}$/', $fileId)) {
            return null; // md5 fallback — no real Slack Canvas behind it
        }
        try {
            $notes = $svc->extractCanvasContent($slack, $fileId);
        } catch (\Throwable $e) {
            return null;
        }
        if (! $notes || trim($notes) === '') {
            return null;
        }
        $parsed = $svc->parseAttendeesSection($notes, $users);
        $ids = array_values(array_unique(array_map('intval', $parsed['user_ids'] ?? [])));
        return empty($ids) ? null : ['ids' => $ids, 'count' => (int) $parsed['count']];
    }

    /**
     * Structural attendee ground truth that needs NO Canvas. Returns
     * ['ids'=>int[], 'source'=>'scheduled'|'dm'] or null when none applies.
     */
    private function structuralAttendees(SlackInsight $row): ?array
    {
        // Scheduled meeting → authoritative roster from the meetings table.
        if (! Str::startsWith((string) $row->meeting_id, 'huddle-')) {
            $key = (string) $row->meeting_id;
            $m = Meeting::where('meeting_key', $key)->first()
                ?? Meeting::where('meeting_key', preg_replace('/-(mon|tue|wed|thu|fri|sat|sun)$/', '', $key))->first();
            if (! $m) {
                return null;
            }
            $att = is_array($m->attendees) ? $m->attendees : [];
            if ($m->owner_id) {
                $att[] = $m->owner_id;
            }
            $att = array_values(array_unique(array_map('intval', array_filter($att))));
            return $att ? ['ids' => $att, 'source' => 'scheduled'] : null;
        }

        // Ad-hoc DM huddle: source_channel_name is a bare Slack id ⇒ a 1:1. Only
        // prune when the stored audience is over-matched (>2) — a ≤2 audience is
        // already 1:1-sized and pruning could drop the genuine other party.
        $ch = ltrim(trim((string) $row->source_channel_name), '#');
        if ($ch !== '' && preg_match('/^[UW][A-Z0-9]{6,}$/', $ch)) {
            $aud = is_array($row->audience_user_ids) ? $row->audience_user_ids : [];
            if (count($aud) <= 2) {
                return null; // already 1:1-sized → leave untouched
            }
            $dmUser = User::where('slack_user_id', $ch)->value('id');
            $set = array_values(array_unique(array_filter([
                $dmUser ? (int) $dmUser : null,
                $row->suggested_assignee_id ? (int) $row->suggested_assignee_id : null,
                $row->assigned_by_user_id ? (int) $row->assigned_by_user_id : null,
            ])));
            return $set ? ['ids' => $set, 'source' => 'dm'] : null;
        }

        return null;
    }

    /** meeting_kind: scheduled key → 'scheduled'; structural ad-hoc (DM) → 'one_on_one'; canvas ad-hoc → from count. */
    private function kindFor(SlackInsight $row, int $count, string $mode): string
    {
        if (! Str::startsWith((string) $row->meeting_id, 'huddle-')) {
            return 'scheduled';
        }
        if (str_starts_with($mode, 'structural')) {
            return 'one_on_one'; // a DM huddle is a 1:1
        }
        if ($count <= 1) {
            return (string) ($row->meeting_kind ?? 'group');
        }
        if ($count === 2) {
            return 'one_on_one';
        }
        $channel = ltrim(trim((string) $row->source_channel_name), '#');
        $isRealChannel = $channel !== ''
            && ! Str::startsWith($channel, 'Slack Huddle')
            && ! preg_match('/^[UW][A-Z0-9]{6,}$/', $channel);
        return $isRealChannel ? 'channel' : 'group';
    }

    /** Resolve the Slack-connected user whose token re-fetches the Canvases. */
    private function resolveCaller(): ?User
    {
        if ($id = $this->option('for-user')) {
            $u = User::find((int) $id);
            if (! $u || ! $u->hasSlackConnection()) {
                $this->error("User {$id} is not Slack-connected.");
                return null;
            }
            return $u;
        }

        return User::whereNotNull('slack_access_token')->where('is_active', true)->first();
    }
}
