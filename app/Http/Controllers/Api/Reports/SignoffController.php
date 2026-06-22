<?php

namespace App\Http\Controllers\Api\Reports;

use App\Http\Controllers\Controller;
use App\Models\DailySignin;
use App\Models\DailySignoff;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Models\WeeklyTimesheet;
use App\Services\ActivityLogService;
use App\Services\SigninAlertService;
use App\Services\SignoffStatusService;
use App\Services\TessaTaskService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SignoffController extends Controller
{
    /**
     * Lightweight presence state for today. The dashboard calls this on every
     * render so we never trust the signedInToday/signedOffToday flags baked
     * into the cached HTML — without this, Sooraj (and anyone whose browser
     * served a stale dashboard from cache) saw the "Click to sign in" toggle
     * even after the server had recorded their sign-off.
     *
     * The HTML wrapper sets no-store, but without explicit cache headers on
     * this JSON response Safari/iOS heuristically caches the GET, replays the
     * pre-sign-off "{signedOff: false}" after a refresh, and the dashboard
     * flips back to the sign-in toggle even though the DailySignoff row is
     * already persisted. Sooraj hit this on 2026-05-21.
     */
    public function dailyState(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $ist = Carbon::now('Asia/Kolkata');
        $dateStr = $ist->format('Y-m-d');

        return response()->json([
            'date' => $dateStr,
            'signedIn' => DailySignin::where('user_id', $user->id)
                ->where('signin_date', $dateStr)
                ->exists(),
            'signedOff' => DailySignoff::where('user_id', $user->id)
                ->where('signoff_date', $dateStr)
                ->exists(),
            'weeklyTimesheetDue' => self::weeklyTimesheetDue($user, $ist, $dateStr),
        ])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache');
    }

    /**
     * Whether to show the mandatory "Weekly Timesheet due" dashboard card: it's
     * the Fri–Sun window, the user fills timesheets (not excluded), isn't on a
     * holiday / full-day leave, and hasn't submitted this week's yet. Mirrors the
     * sign-off gate in SignoffStatusService so the card and the gate agree.
     */
    private static function weeklyTimesheetDue(User $user, Carbon $ist, string $dateStr): bool
    {
        if (! in_array($ist->dayOfWeek, [Carbon::FRIDAY, Carbon::SATURDAY, Carbon::SUNDAY], true)) {
            return false;
        }
        if (in_array((int) $user->id, array_map('intval', config('weekly_timesheet.excluded_user_ids', [])), true)) {
            return false;
        }
        if (array_key_exists($dateStr, config('holidays', []))) {
            return false;
        }
        $onLeave = LeaveRequest::where('user_id', $user->id)
            ->where('status', 'approved')
            ->where('start_date', '<=', $dateStr)
            ->where('end_date', '>=', $dateStr)
            ->whereHas('leaveType', fn ($q) => $q->where('is_hourly', false)->where('slug', '!=', 'wfh'))
            ->exists();
        if ($onLeave) {
            return false;
        }
        $weekStart = $ist->copy()->startOfWeek(Carbon::MONDAY)->format('Y-m-d');

        return ! WeeklyTimesheet::where('user_id', $user->id)
            ->where('week_start', $weekStart)
            ->where('status', 'submitted')
            ->exists();
    }

    public function status(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $dateStr = $request->query('date', '');
        if ($dateStr === '') {
            $dateStr = Carbon::now('Asia/Kolkata')->format('Y-m-d');
        } elseif (! preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($dateStr))) {
            return response()->json(['error' => 'date must be YYYY-MM-DD'], 422);
        } else {
            $dateStr = trim($dateStr);
        }

        $status = SignoffStatusService::getStatus($user, $dateStr);
        if (! ($status['ok'] ?? true)) {
            return response()->json($status, 422);
        }

        return response()->json($status);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $dateStr = Carbon::now('Asia/Kolkata')->format('Y-m-d');
        $status = SignoffStatusService::getStatus($user, $dateStr);

        if (! ($status['ok'] ?? true)) {
            return response()->json($status, 422);
        }

        // Sign-off mirrors the portal's hard gates: an unfilled Daily Report (plus
        // owned-meeting agenda/notes and the Friday review) blocks sign-off, with no
        // override. There used to be a `force` escape hatch — read here and sent only
        // by the MCP sign_off tool — that let it bypass the Daily Report the web never
        // could. It's gone: the gate is now enforced for every client. Already-signed-off
        // callers fall through to the idempotent firstOrCreate response below.
        if (! ($status['canSignOff'] ?? false) && ! ($status['signedOff'] ?? false)) {
            return response()->json([
                'error' => 'Cannot sign off: pending items remain',
                'items' => $status['items'] ?? [],
            ], 422);
        }

        // A sign-off without a sign-in is logically impossible — backfill defensively.
        // Anyone who reaches the sign-off flow has worked today, so use start-of-day in
        // Kolkata as a conservative timestamp if there's no earlier signal.
        DailySignin::firstOrCreate(
            ['user_id' => $user->id, 'signin_date' => $dateStr],
            ['signed_in_at' => Carbon::parse($dateStr, 'Asia/Kolkata')->startOfDay()->setTimezone('UTC')],
        );

        $signoff = DailySignoff::firstOrCreate(
            ['user_id' => $user->id, 'signoff_date' => $dateStr],
            ['signed_off_at' => now(), 'pending_snapshot' => $status['items']],
        );

        if (! $signoff->wasRecentlyCreated) {
            return response()->json([
                'ok' => true,
                'signedOff' => true,
                'signedOffAt' => $signoff->signed_off_at->toIso8601String(),
                'message' => 'Already signed off for today',
            ]);
        }

        ActivityLogService::log($user->id, 'signed_off', "{$user->name} signed off for {$dateStr}", null, null, ['date' => $dateStr]);

        $pendingTasks = app(TessaTaskService::class)->getPendingTasksSummary($user->id);

        // Send Slack reminder for pending tasks on sign-off
        if ($pendingTasks['count'] > 0) {
            try {
                app(TessaTaskService::class)->remindOnSignOff($user);
            } catch (\Throwable $e) {
                // Don't fail sign-off if Slack reminder fails
            }
        }

        // Nudge reporter to verify any tasks awaiting their verification (does not block signoff).
        try {
            app(TessaTaskService::class)->remindToVerifyOnSignOff($user);
        } catch (\Throwable $e) {
            // Don't fail sign-off if Slack reminder fails
        }

        return response()->json([
            'ok' => true,
            'signedOff' => true,
            'signedOffAt' => $signoff->signed_off_at->toIso8601String(),
            'message' => 'Signed off successfully',
            'pendingTasks' => $pendingTasks,
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $dateStr = Carbon::now('Asia/Kolkata')->format('Y-m-d');
        $deleted = DailySignoff::where('user_id', $user->id)
            ->where('signoff_date', $dateStr)
            ->delete();

        if ($deleted > 0) {
            ActivityLogService::log($user->id, 'sign_off_undo', "{$user->name} removed sign-off for {$dateStr}", null, null, ['date' => $dateStr]);
        }

        return response()->json([
            'ok' => true,
            'signedOff' => false,
            'removed' => $deleted > 0,
            'message' => $deleted > 0 ? 'Sign-off removed' : 'No sign-off record for today',
        ]);
    }

    public function signIn(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $dateStr = Carbon::now('Asia/Kolkata')->format('Y-m-d');
        $result = DailySignin::ensureForKolkataDate($user, $dateStr);

        $pendingTasks = app(TessaTaskService::class)->getPendingTasksSummary($user->id);

        if ($result['created']) {
            ActivityLogService::log($user->id, 'signed_in', "{$user->name} signed in for {$dateStr} (dashboard)", null, null, ['date' => $dateStr]);

            // Send Slack reminder for pending tasks on fresh sign-in
            if ($pendingTasks['count'] > 0) {
                try {
                    app(TessaTaskService::class)->remindOnSignIn($user);
                } catch (\Throwable $e) {
                    // Don't fail sign-in if Slack reminder fails
                }
            }

            // Slack DM the user's manager if they opted into team sign-in alerts.
            try {
                app(SigninAlertService::class)->notifyManagerOnSignIn($user, $result['signin']->signed_in_at);
            } catch (\Throwable $e) {
                // Never fail sign-in on a notification error
            }
        }

        return response()->json([
            'ok' => true,
            'signedIn' => true,
            'dashboardSignedIn' => true,
            'signedInAt' => $result['signin']->signed_in_at->toIso8601String(),
            'message' => $result['created'] ? 'Signed in successfully' : 'Already signed in for today',
            'pendingTasks' => $pendingTasks,
        ]);
    }

    public function undoSignIn(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $dateStr = Carbon::now('Asia/Kolkata')->format('Y-m-d');

        // Sign-in is the start-of-attendance signal that drives KRA scoring. We
        // used to allow open-ended deletion via this endpoint, which led to
        // end-of-day data loss when stale clients fired a DELETE on toggle-off
        // (see commit adc2890). To keep "oops I tapped wrong" forgiveness without
        // losing real attendance, only allow undo within 5 minutes of sign-in.
        $signin = DailySignin::where('user_id', $user->id)
            ->where('signin_date', $dateStr)
            ->first();

        if (! $signin) {
            return response()->json([
                'ok' => true,
                'dashboardSignedIn' => false,
                'removed' => false,
                'message' => 'No dashboard sign-in record for today',
            ]);
        }

        $signedInAt = $signin->signed_in_at;
        if ($signedInAt && $signedInAt->diffInMinutes(now()) > 5) {
            // Outside the forgiveness window — preserve the attendance record but
            // keep the response shape so older clients don't crash.
            return response()->json([
                'ok' => true,
                'dashboardSignedIn' => true,
                'removed' => false,
                'message' => 'Sign-in is older than 5 minutes; use Sign Off to end the day.',
            ]);
        }

        $signin->delete();
        ActivityLogService::log($user->id, 'sign_in_undo', "{$user->name} removed dashboard sign-in for {$dateStr}", null, null, ['date' => $dateStr]);

        return response()->json([
            'ok' => true,
            'dashboardSignedIn' => false,
            'removed' => true,
            'message' => 'Dashboard sign-in removed',
        ]);
    }
}
