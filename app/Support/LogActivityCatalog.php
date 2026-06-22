<?php

namespace App\Support;

use App\Models\ActivityLog;

class LogActivityCatalog
{
    /** High-signal actions included in the Logs timeline merge. */
    public const INCLUDED_ACTIONS = [
        // Tasks
        'task_assigned',
        'task_assigned_everyone',
        'task_reassigned',
        'task_updated',
        'task_verified_closed',
        'task_reopened',
        'task_extension_requested',
        'task_extension_approved',
        'task_extension_denied',
        'task_form_confirmed',
        // Daily reports & KPI
        'daily_report_saved',
        'kpi_entry_saved',
        'kpi_target_saved',
        'kpi_ceo_note_saved',
        // Attendance
        'signed_in',
        'signed_off',
        // Meetings
        'meeting_created',
        'meeting_updated',
        'meeting_deleted',
        'meeting_notes_saved',
        // Checklists
        'checklist_assigned',
        'checklist_removed',
        // Tickets
        'ticket_created',
        'ticket_status_changed',
        // Agile
        'story_created',
        'story_status_changed',
        'bug_created',
        'bug_status_changed',
        'sprint_created',
        'sprint_closed',
        // Finance
        'invoice_submitted',
        'invoice_approved',
        'invoice_rejected',
        // Marketing
        'scripts_generated',
        'creative_upload',
        // Leave
        'leave_applied',
        'leave_reviewed',
        'leave_cancelled',
    ];

    /** Collapse multiple field saves into one line per IST day. */
    public const COLLAPSE_ACTIONS = [
        'daily_report_saved',
        'kpi_entry_saved',
        'kpi_target_saved',
    ];

    public static function group(string $action): string
    {
        if (str_starts_with($action, 'task_')) {
            return 'Task';
        }
        if (str_starts_with($action, 'meeting_') || $action === 'discussion_point_') {
            return 'Meeting';
        }
        if (str_starts_with($action, 'daily_report_')) {
            return 'Daily Report';
        }
        if (str_starts_with($action, 'kpi_')) {
            return 'KPI';
        }
        if (str_starts_with($action, 'signed_') || $action === 'sign_off_undo' || $action === 'sign_in_undo') {
            return 'Attendance';
        }
        if (str_starts_with($action, 'checklist_')) {
            return 'Checklist';
        }
        if (str_starts_with($action, 'ticket_')) {
            return 'Ticket';
        }
        if (str_starts_with($action, 'story_') || str_starts_with($action, 'bug_') || str_starts_with($action, 'sprint_')) {
            return 'Agile';
        }
        if (str_starts_with($action, 'invoice_')) {
            return 'Finance';
        }
        if (in_array($action, ['scripts_generated', 'creative_upload'], true) || str_starts_with($action, 'script_')) {
            return 'Marketing';
        }
        if (str_starts_with($action, 'leave_')) {
            return 'Leave';
        }

        return 'Activity';
    }

    /**
     * Collapse chatty per-field saves into one row per IST day (per actor + action).
     *
     * @param  \Illuminate\Support\Collection<int, ActivityLog>|\Illuminate\Database\Eloquent\Collection<int, ActivityLog>  $logs
     * @return list<ActivityLog>
     */
    /**
     * @param  array<int, string>  $names
     */
    public static function collapse($logs, int $viewerId, array $names = []): array
    {
        $kept = [];
        /** @var array<string, array{count: int, latest: ActivityLog}> */
        $buckets = [];

        foreach ($logs as $log) {
            if (! in_array($log->action, self::COLLAPSE_ACTIONS, true)) {
                $kept[] = $log;

                continue;
            }

            $day = $log->created_at->timezone('Asia/Kolkata')->format('Y-m-d');
            $key = $log->action.'|'.$day.'|'.$log->user_id;

            if (! isset($buckets[$key])) {
                $buckets[$key] = ['count' => 0, 'latest' => $log];
            }
            $buckets[$key]['count']++;
            if ($log->created_at >= $buckets[$key]['latest']->created_at) {
                $buckets[$key]['latest'] = $log;
            }
        }

        foreach ($buckets as $bucket) {
            $latest = $bucket['latest'];
            $collapsed = clone $latest;
            $collapsed->description = self::collapsedDescription(
                $latest->action,
                $bucket['count'],
                (int) $latest->user_id,
                $viewerId,
                $names
            );
            $kept[] = $collapsed;
        }

        return $kept;
    }

    /**
     * @param  array<int, string>  $names
     */
    public static function collapsedDescription(string $action, int $count, int $actorId, int $viewerId, array $names = []): string
    {
        $n = max(1, $count);
        $fields = $n === 1 ? '1 field' : "{$n} fields";
        $who = $actorId === $viewerId ? 'You' : ($names[$actorId] ?? 'Someone');

        $verb = match ($action) {
            'daily_report_saved' => 'updated daily report',
            'kpi_entry_saved' => 'saved KPI entries',
            'kpi_target_saved' => 'updated KPI targets',
            default => 'saved',
        };

        if ($who === 'You') {
            return "You {$verb} ({$fields})";
        }

        return "{$who} {$verb} ({$fields})";
    }

    /**
     * Human-readable line for the Logs timeline.
     *
     * @param  array<int, string>  $names  user id => display name
     */
    public static function describe(ActivityLog $log, array $names, int $viewerId): string
    {
        $desc = $log->description;
        $actorId = (int) $log->user_id;
        $you = false;

        if ($actorId === $viewerId && $actorId > 0 && isset($names[$actorId])) {
            $actorName = $names[$actorId];
            if (str_starts_with($desc, $actorName.' ')) {
                $desc = substr($desc, strlen($actorName) + 1);
                $you = true;
            }
        }

        if (preg_match_all('/\buser #(\d+)\b/', $desc, $matches)) {
            foreach ($matches[1] as $idStr) {
                $uid = (int) $idStr;
                if (isset($names[$uid])) {
                    $desc = str_replace('user #'.$uid, $names[$uid], $desc);
                }
            }
        }

        if ($you) {
            $desc = 'You '.lcfirst($desc);
        }

        return $desc;
    }
}
