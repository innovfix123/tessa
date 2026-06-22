<?php

namespace App\Http\Controllers;

use App\Http\Middleware\RoleMiddleware;
use App\Models\DailySignin;
use App\Models\DailySignoff;
use App\Models\KpiDefinition;
use App\Models\Role;
use App\Models\User;
use App\Services\KraScorecardService;
use App\Services\ProjectRoleService;
use App\Services\ScriptGenerationService;
use App\Services\VideoHandoffNotifier;
use App\Support\DailyReportsAccess;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        if ($user->role === Role::SLUG_ADMIN) {
            return redirect('/admin');
        }
        Log::debug('DashboardController::index', [
            'user_id' => $user->id,
            'user_name' => $user->name,
            'portal' => $user->role,
            'role_id' => $user->role_id,
        ]);
        $config = $this->roleConfig($user);
        $config['userName'] = $user->name ?? '';
        $config['userId'] = $user->id;
        $config['roleId'] = $user->role_id;
        $config['roleName'] = $user->designation ?: ($user->roleRelation?->name ?? '');
        $config['meowSoundEnabled'] = (bool) $user->meow_sound_enabled;

        // Sidebar avatar + this-month avg KRA. buildMonth() is heavy, so cache
        // per user+month: the month-keyed key rolls the value over every month
        // automatically, and the 6h TTL keeps it fresh within the month. Only
        // successful computes are cached (a transient failure retries next load)
        // and a KRA error must never break the whole portal render.
        $config['profilePhoto'] = $user->profile_photo_url;

        // JP/Bala/Nandha/Ayush are entirely excluded from KRA — no sidebar
        // widget and no "My Score" scorecard. kraExcluded gates the frontend.
        $kraExcludedIds = array_map('intval', (array) config('kra_exclusions.excluded_user_ids', []));
        $config['kraExcluded'] = in_array((int) $user->id, $kraExcludedIds, true);

        if ($config['kraExcluded']) {
            $config['kraMonth'] = ['label' => '', 'average' => null];
        } else {
            // Show the PREVIOUS completed calendar month's average, held steady
            // until the next month begins (e.g. all through June the widget
            // shows May; on July 1 it rolls over to June). A finished month's
            // score is final, so the number no longer drifts day to day the way
            // a partial current-month average does. startOfMonth() before
            // subMonth() avoids PHP's month-overflow on the 29th–31st.
            $kraYm = Carbon::now('Asia/Kolkata')->startOfMonth()->subMonth()->format('Y-m');
            $kraCacheKey = "kra_month_avg:{$user->id}:{$kraYm}";
            $config['kraMonth'] = Cache::get($kraCacheKey);
            if ($config['kraMonth'] === null) {
                try {
                    $kraData = app(KraScorecardService::class)->buildMonth($user, $kraYm);
                    $config['kraMonth'] = [
                        'label' => $kraData['monthLabel'] ?? '',
                        'average' => $kraData['monthAverage'] !== null ? round((float) $kraData['monthAverage'], 1) : null,
                    ];
                    Cache::put($kraCacheKey, $config['kraMonth'], now()->addHours(6));
                } catch (\Throwable $e) {
                    Log::warning('Sidebar KRA month compute failed', ['user' => $user->id, 'error' => $e->getMessage()]);
                    $config['kraMonth'] = ['label' => '', 'average' => null];
                }
            }
        }

        $metadata = ProjectRoleService::getAllUserMetadata();
        // MODAL_PEOPLE must include ALL active users (including CEO/leadership with no reporting_manager) for owner/attendee dropdowns
        $modalPeople = User::where('is_active', true)->orderBy('name')->get(['id', 'name'])->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values()->toArray();
        $config['MODAL_PEOPLE'] = $modalPeople;
        $config['ACTION_OWNERS'] = $modalPeople;
        // Team members: direct reports of current user
        $config['TEAM_MEMBERS'] = User::where('reporting_manager_id', $user->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])
            ->values()
            ->toArray();
        $config['pendingTeamLeaves'] = \App\Models\LeaveRequest::whereHas('user', fn ($q) => $q->where('reporting_manager_id', $user->id))->where('status', 'pending')->count();
        // Bills/Reimbursements/Travel awaiting payment — only meaningful to the
        // Bills admins (Ayush/Shoyab) who clear the Pay Queue. Drives the sidebar
        // red dot + the dashboard card. 0 for everyone else.
        $billsAdminIds = array_map('intval', (array) config('bills_access.admin_user_ids', []));
        $config['pendingBills'] = in_array((int) $user->id, $billsAdminIds, true)
            ? \App\Models\Bill::where('status', 'pending')->count()
            : 0;
        // Notification-center tab gating (dashboard): HR users get the company-wide
        // Leaves tab; googleConnected drives the Gmail tab's connect/empty state.
        $config['isHr']            = in_array((int) $user->id, array_map('intval', (array) config('hr_leave_alerts.user_ids', [])), true);
        $config['googleConnected'] = $user->hasGoogleConnection();
        // Claude Context — JP (config/claude_context.php) sees the All/Team
        // sub-tab with every employee's daily summary; everyone else sees only
        // their own. Re-checked server-side in ClaudeContextController.
        $config['claudeContextOverview'] = in_array((int) $user->id, array_map('intval', (array) config('claude_context.overview_user_ids', [1])), true);
        // Daily-report entries for the content-creation team are owner-only: the
        // entry popup hides Edit/Delete for non-owner viewers (config-driven).
        $config['dailyReportOwnerOnlyUserIds'] = array_map('intval', (array) config('daily_report_owner_only.user_ids', []));
        // Daily Reports → Excel export (custom date range). Gated to a tiny
        // allow-list (Shoyab/Finance); drives the Export controls in the tab.
        $config['canExportDailyReports'] = in_array((int) $user->id, array_map('intval', (array) config('daily_reports_access.export_user_ids', [])), true);
        $config['kpiDefinitions'] = $this->loadKpiDefinitions($config['kpi'] ?? null);
        $kpiDef = $config['kpiDefinitions'];
        $byPerson = $kpiDef['kpiGroupsByPerson'] ?? [];
        $firstPersonGroups = $byPerson ? ($byPerson[array_key_first($byPerson)]['groups'] ?? []) : [];
        $config['KPI_GROUPS'] = $kpiDef['kpiGroups'] ?? $firstPersonGroups;

        // The portal HTML embeds per-user state inline (signedInToday, signedOffToday,
        // KRA composite, pending counts, etc.). Caching this response makes a refresh
        // after sign-off replay the morning's state and surface the sign-in toggle —
        // Sooraj reported exactly this. The dashboard JS also fetches /api/dashboard-state
        // on render, so both layers must stay fresh.
        return response()
            ->view('dashboards.portal', compact('config'))
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache');
    }

    private function loadKpiDefinitions(?array $kpiConfig): array
    {
        if (! $kpiConfig) {
            Log::debug('DashboardController::loadKpiDefinitions no kpiConfig found, skipping');

            return ['kpiGroups' => [], 'kpiGroupsByPerson' => [], 'aggregation' => [], 'marketingKpiPeople' => []];
        }

        $userIds = $kpiConfig['userIds'] ?? [];
        $isCeoView = $kpiConfig['isCeoView'] ?? false;

        $currentWeekKey = Carbon::now('Asia/Kolkata')->startOfWeek(Carbon::MONDAY)->format('Y-m-d');

        $definitions = KpiDefinition::withTrashed()
            ->visibleForWeek($currentWeekKey)
            ->whereIn('user_id', $userIds)
            ->orderBy('user_id')
            ->orderBy('group_name')
            ->orderBy('sort_order')
            ->get();

        $result = [
            'kpiGroups' => [],
            'kpiGroupsByPerson' => [],
            'aggregation' => $this->buildAggregation($definitions),
            'marketingKpiPeople' => [],
        ];

        if ($isCeoView) {
            $result['marketingKpiPeople'] = $this->buildPeople($definitions);

            return $result;
        }

        $metadata = ProjectRoleService::getAllUserMetadata();
        $byPerson = [];
        foreach ($userIds as $uid) {
            $personDefs = $definitions->where('user_id', $uid);
            $meta = $metadata[$uid] ?? ProjectRoleService::getUserMetadata($uid);
            $byPerson[$uid] = [
                'name' => $meta['name'] ?? (string) $uid,
                'role' => $meta['role'] ?? '',
                'projectName' => $meta['project'] ?? null,
                'reportingManager' => $meta['reporting_manager'] ?? null,
                'groups' => $this->buildKpiGroups($personDefs),
            ];
        }
        $result['kpiGroupsByPerson'] = $byPerson;

        if (count($userIds) === 1) {
            $result['kpiGroups'] = $byPerson[$userIds[0]]['groups'] ?? [];
        }

        return $result;
    }

    private function buildKpiGroups($definitions): array
    {
        $groups = [];
        foreach ($definitions as $d) {
            if (! isset($groups[$d->group_name])) {
                $groups[$d->group_name] = ['name' => $d->group_name, 'fields' => []];
            }
            if ($d->field_key !== '_group_init') {
                $groups[$d->group_name]['fields'][] = [
                    'key' => $d->field_key,
                    'label' => $d->field_label,
                    'id' => $d->id,
                ];
            }
        }

        return array_values($groups);
    }

    private function buildAggregation($definitions): array
    {
        $agg = [];
        foreach ($definitions as $d) {
            if ($d->aggregation) {
                $agg[$d->field_key] = $d->aggregation;
            }
        }

        return $agg;
    }

    private function buildPeople($definitions): array
    {
        $people = [];
        $metadata = ProjectRoleService::getAllUserMetadata();

        foreach ($definitions as $d) {
            $uid = $d->user_id;
            if (! isset($people[$uid])) {
                $meta = $metadata[$uid] ?? null;
                $people[$uid] = [
                    'id' => $uid,
                    'name' => $meta['name'] ?? $d->user?->name ?? '',
                    'role' => $meta['role'] ?? $d->user?->roleRelation?->name ?? '',
                    'project' => $meta['project'] ?? null,
                    'reportingManager' => $meta['reporting_manager'] ?? null,
                    'fields' => [],
                ];
            }
            if (! in_array($d->field_key, ['_placeholder', '_person_init'], true)) {
                $people[$uid]['fields'][] = [
                    'key' => $d->field_key,
                    'label' => $d->field_label,
                    'id' => $d->id,
                    'group' => $d->group_name,
                ];
            }
        }

        return array_values($people);
    }

    private function roleConfig($user): array
    {
        $role = $user->role;
        $allowedUserIds = ProjectRoleService::getAllowedUserIdsForUser($user);

        $svc = ProjectRoleService::class;
        $featureMap = [
            'dashboard' => 'dashboard',
            'meetings' => 'meetings',
            'daily' => 'daily_reports',
            'mkpi' => 'mkpi',
            'org' => 'org',
            'tickets' => 'tickets',
            'revenue' => 'revenue',
            'invoices' => 'invoices',
            'meta_ads' => 'meta_ads',
            'google_ads' => 'google_ads',
            'mission' => 'mission',
            'employees' => 'employees',
            'hr_dashboard' => 'hr_dashboard',
            'team_status' => 'team_status',
            'agile' => 'agile',
        ];
        $features = [];
        foreach ($featureMap as $uiKey => $dbKey) {
            if ($svc::hasFeature($role, $dbKey)) {
                $features[] = $uiKey;
            }
        }

        // Daily Reports rollback (2026-06-18): the "Daily Reports" tab now shows
        // ONLY for the allow-list (Krishnan's Content team + Shoyab). Everyone
        // else loses it here — their end-of-day obligation is the Claude Context
        // summary. Stripped before the rewards/ordering splices below so they see
        // the final feature set, and mirrored in UserFeatureService::featuresFor().
        if (in_array('daily', $features, true) && ! DailyReportsAccess::enabledFor($user)) {
            $features = array_values(array_diff($features, ['daily']));
        }

        // Finance team (Shoyab #32) gets the "Team" (employees) section to view
        // and download employee details/documents + salary for payroll, even
        // though the accountant role lacks feature.employees. Access is read-only
        // — enforced in EmployeeController via FINANCE_VIEW_USER_IDS. Ayush #4
        // (CFO) already has it through his role.
        $financeTeamViewUserIds = [4, 32];
        if (in_array($user->id, $financeTeamViewUserIds, true) && ! in_array('employees', $features, true)) {
            $features[] = 'employees';
        }

        // Salary Tool — standalone CTC<->breakup calculator using the SAME slabs
        // and rules as the offer-letter salary engine (LetterSalaryCalculator).
        // Shoyab #32 (Finance). Gated server-side in SalaryToolController too.
        $salaryToolUserIds = [32];
        if (in_array($user->id, $salaryToolUserIds, true) && ! in_array('salary_tool', $features, true)) {
            $features[] = 'salary_tool';
        }

        // Invoices — the finance Invoices section (supplier-invoice tracking +
        // bank-statement reconciliation) is role-gated (accountant/cfo/ceo/coo/
        // cmo/tech_lead). Bhuvan #59 assists Shoyab on invoice reconciliation, so
        // grant him the tab with full reviewer parity. Authorization is re-checked
        // server-side in InvoiceSubmissionController via EXTRA_REVIEWER_USER_IDS —
        // keep these two lists in sync. This only decides the sidebar tab.
        $invoiceExtraUserIds = [59];
        if (in_array($user->id, $invoiceExtraUserIds, true) && ! in_array('invoices', $features, true)) {
            $features[] = 'invoices';
        }

        // Bills & Reimbursements — per-user allow-lists (submitters) plus the
        // admins (Ayush #4 + Shoyab #32) who pay and reconcile. Single source of
        // truth: config/bills_access.php. Authorization is re-checked in
        // BillController; this only decides the sidebar tab.
        $billsOpenToAll = (bool) config('bills_access.bills_open_to_all', false);
        $billsAllowedIds = array_unique(array_merge(
            config('bills_access.bill_submitter_ids', []),
            config('bills_access.reimbursement_submitter_ids', []),
            config('bills_access.travel_allowance_user_ids', []),
            config('bills_access.admin_user_ids', [])
        ));
        if (($billsOpenToAll || in_array($user->id, $billsAllowedIds, true)) && ! in_array('bills', $features, true)) {
            $features[] = 'bills';
        }

        // Hiring / Recruitment (ATS). HR + management (config hiring_access.roles)
        // and freelance recruiters get the tab by role; panel members get it by
        // being in hiring_access.panel_member_ids or having authored a JD. Single
        // source of truth: config/hiring_access.php. Re-checked in HiringController.
        $isHiringRole = in_array($role, (array) config('hiring_access.roles', []), true)
            || $role === Role::SLUG_FREELANCE_RECRUITER;
        $isHiringPanel = in_array($user->id, (array) config('hiring_access.panel_member_ids', []), true);
        if (! $isHiringRole && ! $isHiringPanel) {
            // Anyone who has authored a JD stays a panel member. Guard the query
            // so a pre-migration call can't fatal the dashboard.
            try {
                $isHiringPanel = \App\Models\JobDescription::where('created_by', $user->id)->exists();
            } catch (\Throwable $e) {
                $isHiringPanel = false;
            }
        }
        if (($isHiringRole || $isHiringPanel) && ! in_array('hiring', $features, true)) {
            $features[] = 'hiring';
        }

        // Per-user agile hide (Bala, Saran, Bhuvan, Bhoomika, Fida, Soundarya). Drop the
        // sidebar nav item too — not just the $agile data block below — so the sidebar
        // never shows an empty Agile tab. Single source of truth for both checks.
        $agileHiddenUserIds = [2, 41, 44, 59, 60, 62];
        if (in_array($user->id, $agileHiddenUserIds, true)) {
            $features = array_values(array_filter($features, fn ($f) => $f !== 'agile'));
        }
        // Hima Revenue Sheet: per-user allow-list (Nandha + Anirudh edit, JP views).
        $himaAllowedIds = array_merge(
            config('hima_revenue.editors', []),
            config('hima_revenue.viewers', [])
        );
        if (in_array($user->id, $himaAllowedIds, true)) {
            $features[] = 'hima_revenue_sheet';
        }
        $onlyCareAllowedIds = config('onlycare_revenue.viewers', []);
        if (in_array($user->id, $onlyCareAllowedIds, true)) {
            $features[] = 'onlycare_revenue_sheet';
        }
        $sudarAllowedIds = config('sudar_revenue.viewers', []);
        if (in_array($user->id, $sudarAllowedIds, true)) {
            $features[] = 'sudar_revenue_sheet';
        }
        // CPA Master Sheet: Anirudh's daily ads/CPA Google Sheet (embedded iframe
        // + daily Hima auto-fill). Per-user allow-list.
        $cpaAllowedIds = config('cpa_master_sheet.viewers', []);
        if (in_array($user->id, $cpaAllowedIds, true)) {
            $features[] = 'cpa_master_sheet';
        }
        // Employee Records: embedded master HR Sheet + Drive folder (HR/leadership, PII).
        if (in_array($user->role, config('hr_records.viewer_roles', []), true)
            || in_array($user->id, config('hr_records.viewer_ids', []), true)) {
            $features[] = 'hr_records';
        }
        // Personal Calendar: month grid backed by the user's own Google Calendar
        // (add all-day notes + dashboard card + morning Slack DM). Per-user allow-list.
        if (in_array($user->id, config('calendar_access.viewer_user_ids', []), true)) {
            $features[] = 'calendar';
        }
        // Team Leave overview — JP only (id 1): global, all employees,
        // month-wise board of leaves by type. Gated again in the API.
        if ((int) $user->id === 1) {
            $features[] = 'team_leave';
        }
        if (! in_array('tasks', $features, true)) {
            $features[] = 'tasks';
        }
        if (! in_array('profile', $features, true)) {
            $features[] = 'profile';
        }
        if (! in_array('leave', $features, true)) {
            $features[] = 'leave';
        }
        if (! in_array('holidays', $features, true)) {
            $features[] = 'holidays';
        }
        // Innovfix Policies — the company policy handbook (public/policies.html),
        // shown to everyone as a read-only reference section.
        if (! in_array('policies', $features, true)) {
            $features[] = 'policies';
        }
        if (! in_array('my_score', $features, true)) {
            $features[] = 'my_score';
        }
        if (! in_array('schedule', $features, true)) {
            $features[] = 'schedule';
        }
        // Slack / GitHub / Google are consolidated into a single "Archives" section.
        // Strip any legacy entries (from role UI keys or older configs) and add Archives once.
        $features = array_values(array_filter($features, fn ($f) => ! in_array($f, ['slack', 'github', 'google'], true)));
        if (! in_array('archives', $features, true)) {
            $features[] = 'archives';
        }
        // Timesheets — per-user allow-list (see config/timesheet_access.php).
        if (in_array($user->id, config('timesheet_access.self_log_user_ids', []), true)) {
            $features[] = 'timesheets';
        }
        // Workforce admin (Weekly Summary) — open to leadership + finance roles. API gate
        // already allows admin/accountant/ceo/cfo; portal sidebar mirrors that so JP (CEO)
        // and Ayush (CFO) both see it.
        if (in_array($role, [Role::SLUG_ADMIN, Role::SLUG_ACCOUNTANT, Role::SLUG_CEO, Role::SLUG_CFO], true)) {
            $features[] = 'workforceAdmin';
        }
        // Offer / Appointment Letters — same allowlist as EmployeeController.
        if (in_array($role, [Role::SLUG_CEO, Role::SLUG_COO, Role::SLUG_CFO, Role::SLUG_HR, Role::SLUG_HR_OPERATIONS, Role::SLUG_BUSINESS_ANALYST], true)) {
            $features[] = 'letters';
        }
        // Timesheet submission tracker — per-user allow-list (see config/timesheet_access.php).
        if (in_array($user->id, config('timesheet_access.tracker_user_ids', []), true)) {
            $features[] = 'timesheetTracker';
        }
        // Weekly Timesheet — company-wide Friday work record. Everyone fills one
        // EXCEPT config('weekly_timesheet.excluded_user_ids') (JP). Managers (with
        // active reports) and HR/leadership also get a "Team" review sub-tab; an
        // excluded reviewer like JP still gets the tab (review-only, no fill form).
        $wtsExcluded = array_map('intval', config('weekly_timesheet.excluded_user_ids', []));
        $wtsReviewerIds = array_map('intval', config('weekly_timesheet.reviewer_user_ids', []));
        $wtsReviewerRoles = [Role::SLUG_CEO, Role::SLUG_COO, Role::SLUG_CFO, Role::SLUG_HR, Role::SLUG_HR_OPERATIONS, Role::SLUG_BUSINESS_ANALYST];
        $wtsCanFill = ! in_array((int) $user->id, $wtsExcluded, true);
        $wtsCompanyWide = in_array($role, $wtsReviewerRoles, true) || in_array((int) $user->id, $wtsReviewerIds, true);
        $wtsCanReview = $wtsCompanyWide
            || User::where('reporting_manager_id', $user->id)->where('is_active', true)->exists();
        if ($wtsCanFill || $wtsCanReview) {
            $features[] = 'weeklyTimesheet';
        }
        // Attendance roster — per-user allow-list (Shoyab + HR; see
        // config/attendance_view_access.php). Read-only view of who signed
        // in, who's on leave, and who's missing for any date.
        if (in_array($user->id, config('attendance_view_access.user_ids', []), true)) {
            $features[] = 'attendance';
        }
        // Team Status — per-user allow-list (config/team_status_access.php).
        // JP (CEO) already gets this via his ceo role; this extends the same
        // company-wide status grid to a hand-picked few (Soundarya #62). The
        // backend endpoint (/api/admin/employee-overview) is widened to match.
        if (in_array($user->id, config('team_status_access.user_ids', []), true) && ! in_array('team_status', $features, true)) {
            $features[] = 'team_status';
        }
        // Sign-In Status grid — color-coded boxes per employee (HR + CEO allow-list).
        $signinStatusUserIds = array_map('intval', (array) config('signin_status_access.user_ids', []));
        if ($role === Role::SLUG_CEO || in_array((int) $user->id, $signinStatusUserIds, true)) {
            if (! in_array('signin_status', $features, true)) {
                $features[] = 'signin_status';
            }
        }
        // Rewards — JP assigns reward tasks; everyone sees their wallet + task
        // list. The Manage tab (create + review) is gated by config('rewards.reviewers')
        // and the Pay tab by config('rewards.payers'), both client-side based on
        // the wallet endpoint's role flags.
        $features[] = 'rewards';
        // Pin the hero tiles to the very top in a fixed order: Dashboard first,
        // then Mission (leadership). Dashboard always outranks Mission for the
        // top spot; whichever the user actually has leads the sidebar.
        $heroOrder = ['dashboard', 'mission'];
        $heroes = array_values(array_filter($heroOrder, fn ($f) => in_array($f, $features, true)));
        if ($heroes) {
            $features = array_values(array_diff($features, $heroes));
            array_splice($features, 0, 0, $heroes);
        }
        // Tasks sits right after the hero block, then Checklists, then Notes.
        // max(_, 1) keeps Tasks at index 1 for the rare role with no hero tile,
        // matching the prior layout.
        if (in_array('tasks', $features, true)) {
            $features = array_values(array_diff($features, ['tasks']));
            array_splice($features, max(count($heroes), 1), 0, ['tasks']);
        }
        // Checklists lives directly under Tasks in the sidebar — it's its own
        // workspace (assigner picks items, assignees tick boxes from the
        // dashboard), but conceptually still a task-adjacent surface.
        if (! in_array('checklists', $features, true)) {
            $features[] = 'checklists';
        }
        $tasksIdx = array_search('tasks', $features, true);
        if ($tasksIdx !== false) {
            $features = array_values(array_diff($features, ['checklists']));
            $tasksIdx = array_search('tasks', $features, true);
            array_splice($features, $tasksIdx + 1, 0, ['checklists']);
        }
        if (! in_array('notes', $features, true)) {
            $features[] = 'notes';
        }
        $tasksIdx = array_search('tasks', $features, true);
        if ($tasksIdx !== false) {
            $features = array_values(array_diff($features, ['notes']));
            // Notes sits below Checklists, so look up tasks index again and
            // skip past the checklists row we just inserted.
            $tasksIdx = array_search('tasks', $features, true);
            array_splice($features, $tasksIdx + 2, 0, ['notes']);
        }
        // Logs sits directly under Notes in the sidebar, available to everyone.
        // Backend (LogEntryController, /api/logs routes, LogEntry model + Slack
        // scan, AI categorizer, and the activity_logs merge) lives alongside it.
        if (! in_array('logs', $features, true)) {
            $notesIdx = array_search('notes', $features, true);
            if ($notesIdx !== false) {
                array_splice($features, $notesIdx + 1, 0, ['logs']);
            } else {
                $features[] = 'logs';
            }
        }
        // Claude Context — daily end-of-day summary Claude pushes over MCP. Every
        // employee logs + sees their own; JP sees all (config/claude_context.php).
        // Sits directly under Logs. Mirrored in UserFeatureService::featuresFor()
        // (a parity test asserts the two feature sets match).
        if (! in_array('claude_context', $features, true)) {
            $logsIdx = array_search('logs', $features, true);
            if ($logsIdx !== false) {
                array_splice($features, $logsIdx + 1, 0, ['claude_context']);
            } else {
                $features[] = 'claude_context';
            }
        }
        if (in_array($role, [Role::SLUG_CEO, Role::SLUG_CFO], true)) {
            $notesIdx = array_search('notes', $features, true);
            $insertAt = $notesIdx !== false ? $notesIdx + 1 : (array_search('tasks', $features, true) !== false ? array_search('tasks', $features, true) + 1 : count($features));
            array_splice($features, $insertAt, 0, ['network_leverage']);
        }
        if ($role === Role::SLUG_CEO && ! in_array('manager_ratings', $features, true)) {
            $features[] = 'manager_ratings';
        }
        // KPI Report — every eligible employee sees their own scorecard read-only;
        // managers fill weekly notes; JP views all + manages KPI defs. Hidden from
        // technical_support EXCEPT Deeksha (id 25). See config/kpi_report.php.
        $kpiExcludedRoles  = (array) config('kpi_report.excluded_roles', []);
        $kpiRoleExceptions = array_map('intval', (array) config('kpi_report.role_exception_user_ids', []));
        $kpiExcludedIds    = array_map('intval', (array) config('kpi_report.excluded_user_ids', []));
        $kpiAdminIds       = array_map('intval', (array) config('kpi_report.admin_user_ids', []));
        $kpiEligible = ! in_array((int) $user->id, $kpiExcludedIds, true)
            && (! in_array($role, $kpiExcludedRoles, true) || in_array((int) $user->id, $kpiRoleExceptions, true));
        if (($kpiEligible || in_array((int) $user->id, $kpiAdminIds, true)) && ! in_array('kpi_report', $features, true)) {
            $features[] = 'kpi_report';
        }
        if ($role === Role::SLUG_CEO && ! in_array('ai_expense', $features, true)) {
            $features[] = 'ai_expense';
        }
        // Pin Rewards directly under Daily Reports in the sidebar.
        $dailyIdx = array_search('daily', $features, true);
        if ($dailyIdx !== false && in_array('rewards', $features, true)) {
            $features = array_values(array_diff($features, ['rewards']));
            $dailyIdx = array_search('daily', $features, true);
            array_splice($features, $dailyIdx + 1, 0, ['rewards']);
        }
        // Place KPI Report + Weekly Timesheet directly below Meetings in the
        // sidebar (in that order). Done last so earlier splices don't move them.
        $belowMeetings = array_values(array_filter(['kpi_report', 'weeklyTimesheet'], fn ($f) => in_array($f, $features, true)));
        if ($belowMeetings) {
            $features = array_values(array_diff($features, $belowMeetings));
            $meetingsIdx = array_search('meetings', $features, true);
            $insertAt = $meetingsIdx !== false ? $meetingsIdx + 1 : count($features);
            array_splice($features, $insertAt, 0, $belowMeetings);
        }
        $hasPreviousMinutes = in_array($role, [Role::SLUG_CEO, Role::SLUG_COO, Role::SLUG_CMO, Role::SLUG_CFO], true);
        $showNextMeetingBanner = $hasPreviousMinutes;

        $dailyReports = null;
        if ($svc::hasFeature($role, 'daily_reports') && DailyReportsAccess::enabledFor($user)) {
            $label = match (true) {
                $role === Role::SLUG_PRODUCT_MANAGER => $user->projects->first()?->name ?? 'Ops',
                $role === Role::SLUG_CMO => 'Marketing',
                $role === Role::SLUG_TECH_LEAD => 'Engineering',
                $role === Role::SLUG_CONTENT_LEAD => 'Content',
                $role === Role::SLUG_TEAM_LEAD_OPERATIONS => 'Team Lead - Operations',
                default => 'Ops',
            };
            $editable = $svc::canEditDailyReport($role);

            // Only expose as daily-report tabs the users who actually have active KPI definitions.
            // A manager with no KPI fields of their own (e.g. a CMO overseeing marketers) shouldn't
            // render as an empty tab — their subordinates carry the data.
            //
            // Daily-reports scope also includes the current user's direct
            // reports (reporting_manager_id) and dotted-line reports
            // (secondary_manager_id). Direct reports matter because
            // getAllowedUserIdsForUser() is role-based: an individual-
            // contributor role (e.g. a Gen AI Developer) who is nonetheless
            // someone's reporting manager would otherwise see no team tabs.
            // This widening is intentionally limited to this section — Friday
            // rating, leaves, and other manager views still resolve scope from
            // reporting_manager_id via their own queries.
            $directReportIds = User::where('reporting_manager_id', $user->id)
                ->where('is_active', true)
                ->pluck('id')
                ->toArray();
            $secondarySubIds = User::where('secondary_manager_id', $user->id)
                ->where('is_active', true)
                ->pluck('id')
                ->toArray();
            $dailyVisibleIds = array_values(array_unique(array_merge($allowedUserIds, $directReportIds, $secondarySubIds)));

            $usersWithKpis = KpiDefinition::whereIn('user_id', $dailyVisibleIds)
                ->whereNull('deleted_at')
                ->whereNotIn('field_key', ['_group_init', '_person_init', '_placeholder'])
                ->distinct()
                ->pluck('user_id')
                ->toArray();
            $dailyUserIds = array_values(array_filter($dailyVisibleIds, fn ($id) => in_array($id, $usersWithKpis, true)));

            $teamMembers = [];
            if (count($dailyUserIds) > 1) {
                $members = User::whereIn('id', $dailyUserIds)
                    ->with(['roleRelation', 'projects', 'reportingManager'])
                    ->get()
                    ->keyBy('id');
                // Preserve the ordering from $dailyUserIds (which follows allowedUserIds hierarchy order).
                foreach ($dailyUserIds as $id) {
                    $m = $members->get($id);
                    if (! $m) {
                        continue;
                    }
                    $teamMembers[] = [
                        'id' => $m->id,
                        'name' => $m->name,
                        'role' => $m->roleRelation?->name ?? '',
                        'roleSlug' => $m->role,
                        'project' => $m->projects->pluck('name')->join(', '),
                        'reportingManager' => $m->reportingManager?->name ?? '—',
                    ];
                }
            }
            // Video Handoff pipeline visibility. Anaz (#18) is the only editor;
            // Krishnan (#20), the admin viewers (JP/Ayush) and the content
            // creators themselves get a read-only view. Creators see only their
            // own row (filtered server-side in VideoHandoffController::index).
            $videoHandoffsView = null;
            if ($user->id === VideoHandoffNotifier::ANAZ_USER_ID) {
                $videoHandoffsView = 'editor';
            } elseif ($user->id === VideoHandoffNotifier::KRISHNAN_USER_ID
                || in_array($user->id, [1, 4], true)
                || VideoHandoffNotifier::isCreator($user->id)
            ) {
                $videoHandoffsView = 'viewer';
            }

            $dailyReports = [
                'userId' => $dailyUserIds[0] ?? null,
                'userIds' => $dailyUserIds,
                'editable' => $editable,
                'label' => $label,
                'teamMembers' => $teamMembers,
                'reportDateOffset' => $role === Role::SLUG_CEO ? 0 : -1,
                'videoHandoffsView' => $videoHandoffsView,
            ];
        }

        $kpi = null;
        if ($svc::hasFeature($role, 'mkpi')) {
            $isCeoView = $role === Role::SLUG_CEO;
            $kpi = [
                'userIds' => $allowedUserIds,
                'isCeoView' => $isCeoView,
                'editable' => $svc::canEditKpiEntry($role),
                'canManage' => $svc::canManageKpiDefinitions($role),
                'canSetTarget' => $svc::canSetKpiTarget($role),
            ];
        }

        $scripts = null;
        if ($svc::hasFeature($role, 'scripts')) {
            $scripts = [
                'canGenerate' => $svc::canGenerateScripts($role),
                'isStatsOnly' => $role === Role::SLUG_CEO,
                'languages' => array_map(
                    fn (string $k) => ['value' => $k, 'label' => ScriptGenerationService::languageLabel($k)],
                    ScriptGenerationService::validLanguages()
                ),
                'categories' => ScriptGenerationService::categoriesForFrontend(),
            ];
        }

        $agile = null;
        // $agileHiddenUserIds is defined once above (single source) and also
        // blanks the agile data block here for those same users.
        if ($svc::hasFeature($role, 'agile') && ! in_array($user->id, $agileHiddenUserIds)) {
            $allowedIds = \App\Services\AgileService::allowedProjectIds($user);
            $agileProjectQuery = \App\Models\Project::orderBy('name');
            if ($allowedIds !== null) {
                $agileProjectQuery->whereIn('id', $allowedIds);
            }
            $agileProjects = $agileProjectQuery->get(['id', 'name'])->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])->values()->toArray();
            $agile = [
                'userId' => $user->id,
                'projects' => $agileProjects,
                'canManageSprints' => $svc::canManageAgileSprints($role),
                'canCloseAnySprint' => in_array((int) $user->id, [34], true),
                'canManageEpics' => $svc::canManageAgileEpics($role),
                'canManageSquads' => $svc::canManageAgileSquads($role),
                'canManageLabels' => $svc::canManageAgileLabels($role),
                'canCrudStories' => $svc::canCrudAgileStories($role),
                'canCrudBugs' => $svc::canCrudAgileBugs($role),
                'canAssignItems' => $svc::canAssignAgileItems($role),
                'canViewDashboard' => $svc::canViewAgileDashboard($role),
                'canManageProjects' => in_array($role, ['tech_lead', 'ceo']),
            ];
        }

        $titleMap = [
            Role::SLUG_CEO => 'CEO Portal',
            Role::SLUG_COO => 'COO Portal',
            Role::SLUG_CFO => 'CFO Portal',
            Role::SLUG_CMO => 'CMO Portal',
            Role::SLUG_TECH_LEAD => 'Tech Lead Portal',
            Role::SLUG_OPS => 'Ops Portal',
            Role::SLUG_MARKETING => 'Marketing Portal',
            Role::SLUG_GROWTH_MANAGER => 'Growth Manager Portal',
            Role::SLUG_VIDEO_EDITOR => 'Video Editor Portal',
            Role::SLUG_GRAPHIC_DESIGNER => 'Graphic Designer Portal',
            Role::SLUG_CONTENT_LEAD => 'Content Lead Portal',
            Role::SLUG_CONTENT_CREATOR => 'Content Creator Portal',
            Role::SLUG_SOCIAL_MEDIA => 'Social Media Portal',
            Role::SLUG_HR => 'HR Portal',
            Role::SLUG_HR_OPERATIONS => 'HR Portal',
            Role::SLUG_FULL_STACK_DEVELOPER => 'Full Stack Developer Portal',
            Role::SLUG_GEN_AI_DEVELOPER => 'Gen AI Developer Portal',
            Role::SLUG_LEAD_AI_ENGINEER => 'Lead AI Engineer Portal',
            Role::SLUG_ACCOUNTANT => 'Accountant Portal',
            Role::SLUG_QA_ANALYST => 'AI-QA Portal',
            Role::SLUG_CONTENT_MODERATOR_QA => 'Content Moderator & QA Portal',
            Role::SLUG_FOUNDERS_OFFICE => "Founder's Office Portal",
        ];

        if (! empty($user->designation) && stripos($user->designation, 'intern') !== false) {
            // Interns are cloned onto a functional role (e.g. gen_ai_developer)
            // for features but keep their "AI Intern" designation. The portal
            // title must reflect who they are, not the feature-gating role —
            // so an intern designation always wins over the role title map.
            $title = $user->designation.' Portal';
        } elseif (isset($titleMap[$role])) {
            $title = $titleMap[$role];
        } elseif (! empty($user->designation)) {
            $title = $user->designation.' Portal';
        } else {
            $title = ucfirst(str_replace('_', ' ', $role)).' Portal';
        }
        if ($role === Role::SLUG_PRODUCT_MANAGER) {
            $projectName = $user->projects->first()?->name;
            if ($projectName) {
                $title = $projectName.' PM Portal';
            }
        }

        // Bespoke per-user portal titles. These win over the role-based title
        // above so an individual can carry a custom title without changing the
        // role (which still drives feature-gating). Fida (#41) is "Lead AI
        // Engineer" while every other gen_ai_developer keeps "Gen AI Developer
        // Portal".
        $titleOverridesByUserId = [
            41 => 'Lead AI Engineer Portal',
        ];
        $title = $titleOverridesByUserId[$user->id] ?? $title;

        // Today's birthdays (Asia/Kolkata). Only id + name ever leave the
        // server — the birth year is never exposed to the team. month-day is
        // compared in PHP so leap-day handling and indexing stay simple.
        $bdayTodayMd = Carbon::now('Asia/Kolkata')->format('m-d');
        $allBirthdayUsers = User::where('is_active', true)
            ->whereNotIn('id', config('birthday_exclusions.user_ids', [])) // opted-out of birthday surfaces
            ->whereNotNull('date_of_birth')
            ->get(['id', 'name', 'date_of_birth']);
        $todaysBirthdays = $allBirthdayUsers
            ->filter(fn ($b) => $b->date_of_birth->format('m-d') === $bdayTodayMd)
            ->map(fn ($b) => ['id' => $b->id, 'name' => $b->name])
            ->values()
            ->all();
        // Lets the portal celebrate the signed-in user vs. merely notify them
        // about a colleague — without shipping anyone's birth year.
        $myBirthday = [
            'is' => $user->date_of_birth
                && $user->date_of_birth->format('m-d') === $bdayTodayMd
                && ! in_array($user->id, config('birthday_exclusions.user_ids', []), true),
            'name' => $user->name,
        ];
        // Holidays-and-Birthdays view: ship only id + name + md (no year ever
        // leaves the server). Sorted by next-occurrence so the upcoming list
        // already arrives in display order.
        $todayMd = $bdayTodayMd;
        $birthdays = $allBirthdayUsers
            ->map(fn ($b) => ['id' => $b->id, 'name' => $b->name, 'md' => $b->date_of_birth->format('m-d')])
            ->sortBy(fn ($b) => ($b['md'] >= $todayMd ? '0' : '1').$b['md'])
            ->values()
            ->all();

        // Bills sits directly above Org Chart in the sidebar (when the user has
        // both). Done last so it overrides the earlier append position.
        if (in_array('bills', $features, true) && in_array('org', $features, true)) {
            $features = array_values(array_filter($features, fn ($f) => $f !== 'bills'));
            $orgIdx = array_search('org', $features, true);
            array_splice($features, $orgIdx, 0, ['bills']);
        }

        // Freelance recruiters get a deliberately stripped portal: ONLY the
        // Hiring tab. They are external recruiters, not employees — no Dashboard
        // (so the daily sign-in gate never applies) and none of the Profile /
        // Leave / KRA / Rewards items the blocks above add for everyone. Access
        // to /api/hiring is re-checked in HiringController.
        if ($role === Role::SLUG_FREELANCE_RECRUITER) {
            $features = ['hiring'];
        }

        // New-hire onboarding gate (Feature 4): a new joiner (onboarding_required)
        // is restricted for the whole of their probation. The allow-list grows in
        // phases — Profile + Checklist always; Daily Reports unlocks once their
        // profile/onboarding is complete — and the lock lifts entirely only when
        // probation ends (employee_status leaves probation/intern). Strictly scoped
        // to onboarding_required users on probation, so no existing user is affected.
        $onboardingLocked = false;
        $onboardingStatus = null;
        $onboardingAllowedViews = [];
        if (! empty($user->onboarding_required) && in_array($user->employee_status, ['probation', 'intern'], true)) {
            $onboardingStatus = app(\App\Services\OnboardingService::class)->status($user);
            $onboardingLocked = true;
            $profileComplete = ! empty($onboardingStatus['complete']);
            $onboardingAllowedViews = $profileComplete
                ? ['profile', 'checklists', 'daily_reports']
                : ['profile', 'checklists'];
            if (! in_array('profile', $features, true)) {
                $features[] = 'profile';
            }
            // Land on My Profile.
            $features = array_values(array_merge(['profile'], array_filter($features, fn ($f) => $f !== 'profile')));
        }

        // JP AI Command Center: collapse JP's sidebar to just Dashboard + AI. The
        // full $features list still flows to the frontend, and the blade keeps every
        // section's nav link in the DOM (just CSS-hidden) so switchView / hash-sync
        // work normally. Gated to JP + the JP_AI_MODE flag, so no other user is
        // affected and the kill switch fully reverts behaviour.
        $jpAiMode = config('jp_ai.enabled') && (int) $user->id === (int) config('jp_ai.user_id', 1);

        return [
            'portal' => $role,
            'title' => $title,
            'layout' => 'full',
            'hasPreviousMinutes' => $hasPreviousMinutes,
            'showNextMeetingBanner' => $showNextMeetingBanner,
            'features' => $features,
            'jp_ai_mode' => $jpAiMode,
            'onboardingLocked' => $onboardingLocked,
            'onboardingAllowedViews' => $onboardingAllowedViews,
            'onboarding' => $onboardingStatus,
            'hasSignoff' => $svc::hasFeature($role, 'signoff'),
            'signedInToday' => DailySignin::where('user_id', $user->id)->where('signin_date', Carbon::now('Asia/Kolkata')->format('Y-m-d'))->exists(),
            'signedOffToday' => DailySignoff::where('user_id', $user->id)->where('signoff_date', Carbon::now('Asia/Kolkata')->format('Y-m-d'))->exists(),
            'dailyReports' => $dailyReports,
            'kpi' => $kpi,
            'scripts' => $scripts,
            'agile' => $agile,
            'weeklyTimesheet' => [
                'userId' => $user->id,
                'userName' => $user->name,
                'canFill' => $wtsCanFill,
                'canReview' => $wtsCanReview,
            ],
            'holidays' => config('holidays', []),
            'birthdays' => $birthdays,
            'todaysBirthdays' => $todaysBirthdays,
            'myBirthday' => $myBirthday,
        ];
    }

}
