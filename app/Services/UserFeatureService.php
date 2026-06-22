<?php

namespace App\Services;

use App\Models\JobDescription;
use App\Models\Role;
use App\Models\User;
use App\Support\DailyReportsAccess;

/**
 * Single source of truth for "which feature tabs does this user have".
 *
 * Mirrors the MEMBERSHIP decisions in DashboardController::roleConfig()
 * (the set of feature keys, not the sidebar ordering). Consumed by the MCP
 * layer (App\Mcp\Tool::isAvailableTo via featureKey) so each Claude
 * connector exposes exactly the tools that user's Tessa login can use.
 *
 * A parity test asserts featuresFor() === the set of roleConfig()['features']
 * for every active user. Until DashboardController is converged onto this
 * service, the two MUST be kept in sync — see the plan file.
 */
class UserFeatureService
{
    /** Per-request memo so toolsForUser() doesn't recompute for all ~60 tools. */
    private static array $cache = [];

    /** @return string[] feature keys the user has (unordered, unique). */
    public static function featuresFor(User $user): array
    {
        if (array_key_exists($user->id, self::$cache)) {
            return self::$cache[$user->id];
        }

        $role = (string) $user->role;
        $f = [];

        // 1. Role-permission features (DashboardController featureMap; note UI
        //    key 'daily' maps to the 'daily_reports' permission).
        $featureMap = [
            'dashboard' => 'dashboard', 'meetings' => 'meetings', 'daily' => 'daily_reports',
            'kpi' => 'kpi', 'mkpi' => 'mkpi', 'org' => 'org', 'tickets' => 'tickets',
            'revenue' => 'revenue', 'invoices' => 'invoices', 'meta_ads' => 'meta_ads',
            'google_ads' => 'google_ads', 'mission' => 'mission', 'employees' => 'employees',
            'hr_dashboard' => 'hr_dashboard', 'team_status' => 'team_status', 'agile' => 'agile',
        ];
        foreach ($featureMap as $uiKey => $dbKey) {
            if (ProjectRoleService::hasFeature($role, $dbKey)) {
                $f[] = $uiKey;
            }
        }

        // 2. Finance team view (Ayush #4 + Shoyab #32) → employees.
        if (in_array($user->id, [4, 32], true)) {
            $f[] = 'employees';
        }
        // 3. Salary tool (Shoyab #32).
        if (in_array($user->id, [32], true)) {
            $f[] = 'salary_tool';
        }
        // 4. Bills — open-to-all flag OR per-user allowlists (config/bills_access.php).
        $billsAllowed = array_unique(array_merge(
            (array) config('bills_access.bill_submitter_ids', []),
            (array) config('bills_access.reimbursement_submitter_ids', []),
            (array) config('bills_access.travel_allowance_user_ids', []),
            (array) config('bills_access.admin_user_ids', []),
        ));
        if ((bool) config('bills_access.bills_open_to_all', false) || in_array($user->id, $billsAllowed, true)) {
            $f[] = 'bills';
        }
        // Invoices — finance roles get the 'invoices' feature via the invoices
        // permission (step 1 above). Bhuvan #59 assists Shoyab on invoice
        // reconciliation and gets it with full reviewer parity. Mirror of
        // $invoiceExtraUserIds in DashboardController + EXTRA_REVIEWER_USER_IDS
        // in InvoiceSubmissionController — keep all three in sync.
        if (in_array($user->id, [59], true) && ! in_array('invoices', $f, true)) {
            $f[] = 'invoices';
        }
        // 5. Hiring — role, panel member, or anyone who authored a JD.
        $isHiringRole = in_array($role, (array) config('hiring_access.roles', []), true)
            || $role === Role::SLUG_FREELANCE_RECRUITER;
        $isHiringPanel = in_array($user->id, (array) config('hiring_access.panel_member_ids', []), true);
        if (! $isHiringRole && ! $isHiringPanel) {
            try {
                $isHiringPanel = JobDescription::where('created_by', $user->id)->exists();
            } catch (\Throwable $e) {
                $isHiringPanel = false;
            }
        }
        if ($isHiringRole || $isHiringPanel) {
            $f[] = 'hiring';
        }
        // 6. Revenue sheets (per-user allowlists).
        if (in_array($user->id, array_merge((array) config('hima_revenue.editors', []), (array) config('hima_revenue.viewers', [])), true)) {
            $f[] = 'hima_revenue_sheet';
        }
        if (in_array($user->id, (array) config('onlycare_revenue.viewers', []), true)) {
            $f[] = 'onlycare_revenue_sheet';
        }
        if (in_array($user->id, (array) config('sudar_revenue.viewers', []), true)) {
            $f[] = 'sudar_revenue_sheet';
        }
        // 7. Team Leave overview — JP only (id 1).
        if ((int) $user->id === 1) {
            $f[] = 'team_leave';
        }
        // 8. Always-on tabs (everyone).
        array_push($f, 'tasks', 'profile', 'leave', 'holidays', 'policies', 'my_score', 'schedule', 'archives', 'rewards', 'checklists', 'notes', 'logs', 'claude_context');
        // 9. Per-user / per-role extras.
        if (in_array($user->id, (array) config('timesheet_access.self_log_user_ids', []), true)) {
            $f[] = 'timesheets';
        }
        if (in_array($role, [Role::SLUG_ADMIN, Role::SLUG_ACCOUNTANT, Role::SLUG_CEO, Role::SLUG_CFO], true)) {
            $f[] = 'workforceAdmin';
        }
        if (in_array($role, [Role::SLUG_CEO, Role::SLUG_COO, Role::SLUG_CFO, Role::SLUG_HR, Role::SLUG_HR_OPERATIONS, Role::SLUG_BUSINESS_ANALYST], true)) {
            $f[] = 'letters';
        }
        if (in_array($user->id, (array) config('timesheet_access.tracker_user_ids', []), true)) {
            $f[] = 'timesheetTracker';
        }
        if (in_array($user->id, (array) config('attendance_view_access.user_ids', []), true)) {
            $f[] = 'attendance';
        }
        if (in_array($user->id, (array) config('team_status_access.user_ids', []), true)) {
            $f[] = 'team_status';
        }
        if (in_array($role, [Role::SLUG_CEO, Role::SLUG_CFO], true)) {
            $f[] = 'network_leverage';
        }
        if ($role === Role::SLUG_CEO) {
            $f[] = 'manager_ratings';
            $f[] = 'ai_expense';
        }

        // 10. Removes (per-user hides).
        if (in_array($user->id, [2, 41, 44, 59, 60, 62], true)) {
            $f = array_filter($f, fn ($x) => $x !== 'agile');
        }
        // Daily Reports rollback (2026-06-18): the tab applies only to the
        // allow-list (Krishnan's Content team + Shoyab). Mirrors the strip in
        // DashboardController::roleConfig() so the two feature sets stay in parity.
        if (in_array('daily', $f, true) && ! DailyReportsAccess::enabledFor($user)) {
            $f = array_filter($f, fn ($x) => $x !== 'daily');
        }

        // 11. Freelance recruiters: a deliberately stripped, hiring-only portal
        //     (overrides everything above).
        if ($role === Role::SLUG_FREELANCE_RECRUITER) {
            $f = ['hiring'];
        }

        return self::$cache[$user->id] = array_values(array_unique($f));
    }
}
