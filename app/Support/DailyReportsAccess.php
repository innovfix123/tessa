<?php

namespace App\Support;

use App\Models\User;

/**
 * Single source of truth for "does this user still have Daily Reports?".
 *
 * Daily Reports were rolled back company-wide on 2026-06-18 (see
 * config/daily_reports_access.php). The feature — the sidebar tab, the sign-off
 * gate, the dashboard "pending" card and the KRA discipline component — now
 * applies ONLY to the allow-list; every other employee is gated onto their daily
 * "Claude Context" summary instead.
 *
 * A user keeps Daily Reports when they are:
 *   - listed explicitly in config('daily_reports_access.user_ids'), or
 *   - one of config('daily_reports_access.manager_ids'), or anywhere in that
 *     manager's reporting subtree (so Krishnan's whole Content team is covered,
 *     including future hires, without hard-coding every id).
 *
 * Route ALL daily-report gating through enabledFor() so the four surfaces stay
 * consistent: no tab => not required to sign off => no pending card => not scored.
 */
class DailyReportsAccess
{
    /** Per-process memo: user id => bool. */
    private static array $memo = [];

    public static function enabledFor(User $user): bool
    {
        $uid = (int) $user->id;
        if (array_key_exists($uid, self::$memo)) {
            return self::$memo[$uid];
        }

        $userIds = array_map('intval', (array) config('daily_reports_access.user_ids', []));
        if (in_array($uid, $userIds, true)) {
            return self::$memo[$uid] = true;
        }

        $managerIds = array_map('intval', (array) config('daily_reports_access.manager_ids', []));
        if (empty($managerIds)) {
            return self::$memo[$uid] = false;
        }

        // Walk up the reporting chain: enabled if the user themselves or any
        // ancestor is one of the listed managers. $seen guards against a cycle.
        $seen = [];
        $current = $user;
        while ($current) {
            $cid = (int) $current->id;
            if (in_array($cid, $managerIds, true)) {
                return self::$memo[$uid] = true;
            }
            if (isset($seen[$cid])) {
                break;
            }
            $seen[$cid] = true;
            $mid = $current->reporting_manager_id;
            if (empty($mid)) {
                break;
            }
            $current = User::find((int) $mid);
        }

        return self::$memo[$uid] = false;
    }
}
