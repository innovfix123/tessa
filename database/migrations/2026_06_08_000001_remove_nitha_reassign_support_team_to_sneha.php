<?php

use App\Models\Meeting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Nitha Sheri (#66, Team Lead-Operations, probation) leaves the company. Her five
 * Technical Support reports and the support function roll up DIRECTLY to Sneha
 * Sunoj (#5, Ops Manager) — no intermediate lead.
 *
 *   1. Re-point Deeksha (#25), Gousia (#26), Reshma (#28), Nisha (#47),
 *      Anjali (#48) from Nitha -> Sneha (primary). They already carried Sneha as
 *      a dotted-line secondary; that becomes redundant, so it is cleared.
 *   2. Reassign the "Support Team Standup" (meeting #102) from Nitha -> Sneha
 *      (portal team_lead_operations -> ops) so it keeps running under the new
 *      manager. Attendees + attendees_only are unchanged.
 *   3. Soft-terminate Nitha: employee_status='terminated' (=> is_active=0 via
 *      syncIsActive), exit fields stamped, Slack/Google access revoked. The row
 *      is preserved (reversible, no FK breakage); she simply drops off every
 *      is_active-filtered surface — the app's canonical "person has left" state.
 *
 * Ordering is load-bearing: the users.reporting_manager_id FK is nullOnDelete, so
 * the reports MUST be moved off Nitha before she is touched. (We soft-deactivate
 * rather than delete, but the move-first ordering is kept regardless.)
 *
 * Meghana (#45) already reports to Sneha — no DB change there; only the static
 * org chart (public/shared/org.js) is updated separately.
 */
return new class extends Migration
{
    private const NITHA = 66;
    private const SNEHA = 5;

    /** Deeksha, Gousia, Reshma, Nisha, Anjali — all currently reporting to Nitha. */
    private const REPORTS = [25, 26, 28, 47, 48];

    private const MEETING_KEY = 'support-team-standup';

    public function up(): void
    {
        $today = Carbon::now('Asia/Kolkata')->toDateString();

        DB::transaction(function () use ($today) {
            // 1. Re-point the five reports off Nitha -> Sneha (guarded => idempotent,
            //    and won't clobber a later manual reassignment).
            User::whereIn('id', self::REPORTS)
                ->where('reporting_manager_id', self::NITHA)
                ->update(['reporting_manager_id' => self::SNEHA]);

            // Sneha is now their primary; drop the now-redundant dotted line.
            User::whereIn('id', self::REPORTS)
                ->where('secondary_manager_id', self::SNEHA)
                ->update(['secondary_manager_id' => null]);

            // 2. Reassign the Support Team Standup to Sneha (only while Nitha still
            //    owns it). Attendees / attendees_only / recurrence are left intact;
            //    portal -> 'ops' so it surfaces in Sneha's portal (portal is VARCHAR
            //    since 2026_06_03_000002).
            $sneha = User::find(self::SNEHA);
            if ($sneha) {
                Meeting::where('meeting_key', self::MEETING_KEY)
                    ->where('owner_id', self::NITHA)
                    ->update([
                        'owner'      => $sneha->name,
                        'owner_id'   => self::SNEHA,
                        'created_by' => self::SNEHA,
                        'portal'     => 'ops',
                    ]);
            }

            // 3. Soft-terminate Nitha (mirrors EmployeeController::handleStatusChange
            //    'terminated' case). Only act while she is still active so a re-run
            //    doesn't re-stamp the exit dates.
            $nitha = User::find(self::NITHA);
            if ($nitha && in_array($nitha->employee_status, ['active', 'probation', 'intern'], true)) {
                $nitha->employee_status   = 'terminated';
                $nitha->exit_date         = $today;
                $nitha->last_working_date = $today;
                $nitha->exit_reason       = 'Removed from Tessa — role discontinued (probation)';
                $nitha->syncIsActive();   // 'terminated' ∉ active statuses => is_active = 0
                $nitha->save();           // User::$timestamps = false

                // Revoke lingering access so nothing keeps polling on her behalf.
                $nitha->disconnectSlack();
                $nitha->disconnectGoogle();
            }

            // 4. Defensive: never leave a department pointing its head at an inactive user.
            if (Schema::hasColumn('departments', 'head_user_id')) {
                DB::table('departments')->where('head_user_id', self::NITHA)->update(['head_user_id' => null]);
            }
        });
    }

    public function down(): void
    {
        // NOTE: Nitha's Slack/Google OAuth tokens are NOT restorable here — she
        // would need to reconnect. Everything else is reverted to the prior state.
        DB::transaction(function () {
            // Reports back under Nitha (primary) with Sneha as the dotted-line
            // secondary — only if they still point at Sneha.
            User::whereIn('id', self::REPORTS)
                ->where('reporting_manager_id', self::SNEHA)
                ->update([
                    'reporting_manager_id' => self::NITHA,
                    'secondary_manager_id' => self::SNEHA,
                ]);

            // Standup back to Nitha (only if still owned by Sneha).
            $nitha = User::find(self::NITHA);
            if ($nitha) {
                Meeting::where('meeting_key', self::MEETING_KEY)
                    ->where('owner_id', self::SNEHA)
                    ->update([
                        'owner'      => $nitha->name,
                        'owner_id'   => self::NITHA,
                        'created_by' => self::NITHA,
                        'portal'     => 'team_lead_operations',
                    ]);

                // Reactivate Nitha (only if we were the ones who terminated her).
                if ($nitha->employee_status === 'terminated') {
                    $nitha->employee_status   = 'probation';
                    $nitha->exit_date         = null;
                    $nitha->last_working_date = null;
                    $nitha->exit_reason       = null;
                    $nitha->syncIsActive();   // 'probation' => is_active = 1
                    $nitha->save();
                }
            }
        });
    }
};
