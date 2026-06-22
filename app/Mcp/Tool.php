<?php

namespace App\Mcp;

use App\Models\User;
use Illuminate\Http\Request;

abstract class Tool
{
    /**
     * Central tool-name → portal-feature map. When a tool's name appears
     * here it's only shown/callable if the user has that feature per
     * UserFeatureService (which mirrors DashboardController::roleConfig).
     * Tools NOT listed have no feature gate (e.g. whoami, list_my_kras) —
     * they may still set allowedRoleSlugs/requiredPermission (admin tools).
     */
    protected const FEATURE_MAP = [
        // Tasks (everyone except the hiring-only freelance_recruiter portal)
        'list_tasks' => 'tasks', 'get_task' => 'tasks', 'create_task' => 'tasks',
        'update_task' => 'tasks', 'delete_task' => 'tasks', 'list_my_action_needed' => 'tasks',
        'list_team_action_needed' => 'tasks', 'add_task_checkin' => 'tasks',
        'extend_task_deadline' => 'tasks', 'approve_task_extension' => 'tasks',
        'verify_task' => 'tasks', 'reopen_task' => 'tasks', 'create_subtask' => 'tasks',
        'list_task_blockers' => 'tasks', 'create_task_blocker' => 'tasks', 'list_pending_work' => 'tasks',
        'redirect_task' => 'tasks', 'nudge_task' => 'tasks', 'escalate_task' => 'tasks',
        'get_task_thread' => 'tasks', 'post_task_thread' => 'tasks', 'invite_to_task' => 'tasks',
        'delete_subtask' => 'tasks', 'delete_task_blocker' => 'tasks', 'delete_recurrence' => 'tasks',
        'create_recurrence' => 'tasks', 'update_recurrence' => 'tasks', 'list_recurrences' => 'tasks',
        // Checklists
        'assign_checklist' => 'checklists', 'list_checklists' => 'checklists',
        'toggle_checklist_item' => 'checklists', 'save_checklist_item_note' => 'checklists',
        'delete_checklist' => 'checklists', 'update_checklist' => 'checklists',
        // Profile / personal
        'get_profile' => 'profile', 'update_profile' => 'profile', 'list_holidays' => 'holidays',
        // Logs
        'list_logs' => 'logs', 'add_log' => 'logs', 'delete_log' => 'logs',
        // Claude Context (every employee has 'claude_context'; stripped portals don't)
        'log_claude_context' => 'claude_context', 'get_my_claude_context' => 'claude_context',
        // Leave
        'list_leave_types' => 'leave', 'list_leave_requests' => 'leave', 'request_leave' => 'leave',
        'review_leave' => 'leave', 'cancel_leave' => 'leave', 'list_team_pending_leaves' => 'leave',
        'request_leave_cancellation' => 'leave', 'review_leave_cancellation' => 'leave',
        // Daily reports
        'list_daily_reports' => 'daily', 'update_daily_report_field' => 'daily',
        // KPIs — team KPI tools gated by 'kpi' OR 'mkpi' (managers/CEO see their
        // team's KPIs via the manager-KPI surface); personal KRAs by 'my_score'.
        'list_kpi_definitions' => ['kpi', 'mkpi'], 'list_user_kpis' => ['kpi', 'mkpi'], 'list_my_kras' => 'my_score',
        // Meetings
        'list_meetings' => 'meetings', 'get_meeting' => 'meetings',
        'list_action_items' => 'meetings', 'save_meeting_note' => 'meetings', 'create_meeting' => 'meetings',
        'delete_meeting' => 'meetings',
        'record_meeting_attendance' => 'meetings', 'meeting_attendance_summary' => 'meetings',
        'meeting_attendance_overview' => 'meetings', 'pending_meeting_notes' => 'meetings',
        // Meeting scheduler (one-off scheduled meetings)
        'analyze_meeting_schedule' => 'schedule', 'create_scheduled_meeting' => 'schedule',
        'reschedule_meeting' => 'schedule', 'skip_scheduled_meeting' => 'schedule',
        'list_scheduled_meetings' => 'schedule', 'delete_scheduled_meeting' => 'schedule',
        // Dashboard notes
        'list_dashboard_notes' => 'notes', 'create_dashboard_note' => 'notes',
        'update_dashboard_note' => 'notes', 'delete_dashboard_note' => 'notes', 'create_reminder' => 'notes',
        // Employees / HR directory — available to everyone who can create a task
        // (the 'tasks' feature, i.e. all staff except the hiring-only freelance
        // recruiter portal). Needed so any employee can resolve a name → user_id
        // before create_task/invite_to_task/request_leave. Non-HR callers get a
        // lightweight id+name+designation roster; HR/finance still get the rich
        // /employees payload — see ListEmployeesTool::handle(). list_departments/
        // list_designations stay role-gated in their own tool classes.
        'list_employees' => 'tasks',
        // Single full employee profile + status (get_employee) is HR/finance-only
        // — it exposes PII (addresses, documents, salary), so gate by 'employees'.
        'get_employee' => 'employees',
        'get_salary_history' => 'employees', 'get_promotion_history' => 'employees',
        'get_hr_dashboard' => 'hr_dashboard', 'compute_salary' => 'salary_tool',
        // Letters
        'list_letters' => 'letters', 'preview_letter' => 'letters', 'delete_letter' => 'letters',
        // Bills
        'list_my_bills' => 'bills', 'delete_bill' => 'bills',
        // Invoices (finance supplier-invoice tracking; reviewers see all)
        'list_invoices' => 'invoices', 'get_invoice_reconciliation' => 'invoices',
        // Finance — revenue + Hima revenue sheet
        'get_revenue_payout' => 'revenue',
        'hima_revenue_months' => 'hima_revenue_sheet', 'get_hima_revenue' => 'hima_revenue_sheet',
        'update_hima_revenue' => 'hima_revenue_sheet',
        // Marketing ad reports
        'list_meta_ad_reports' => 'meta_ads', 'list_google_ad_reports' => 'google_ads',
        // Network leverage
        'list_network_leverage' => 'network_leverage', 'add_network_leverage' => 'network_leverage',
        'delete_network_leverage' => 'network_leverage',
        // Archives — Tessa AI insight cards (Slack + Gmail)
        'list_slack_insights' => 'archives', 'snooze_slack_insight' => 'archives',
        'create_task_from_slack_insight' => 'archives', 'clear_slack_insights' => 'archives',
        'list_gmail_insights' => 'archives', 'snooze_gmail_insight' => 'archives',
        'create_task_from_gmail_insight' => 'archives', 'clear_gmail_insights' => 'archives',
        // Agile
        'list_squads' => 'agile', 'get_sprint_board' => 'agile', 'list_epics' => 'agile',
        'list_stories' => 'agile', 'create_story' => 'agile', 'update_story_status' => 'agile',
        'list_bugs' => 'agile', 'create_bug' => 'agile', 'delete_story' => 'agile', 'delete_bug' => 'agile',
        'activate_sprint' => 'agile', 'review_sprint' => 'agile', 'close_sprint' => 'agile', 'reopen_sprint' => 'agile',
        'create_sprint' => 'agile', 'update_sprint' => 'agile',
        'create_squad' => 'agile', 'update_squad' => 'agile', 'add_squad_member' => 'agile', 'remove_squad_member' => 'agile',
        'create_epic' => 'agile', 'update_epic' => 'agile', 'delete_epic' => 'agile',
        'update_story' => 'agile', 'update_bug' => 'agile', 'move_bug' => 'agile',
        'create_project' => 'agile', 'update_project' => 'agile', 'delete_project' => 'agile',
        'list_labels' => 'agile', 'create_label' => 'agile', 'delete_label' => 'agile',
        'get_agile_dashboard' => 'agile', 'get_sprint_burndown' => 'agile', 'get_sprint_capacity' => 'agile',
        // Support tickets
        'list_tickets' => 'tickets',
        // Hiring
        'list_candidates' => 'hiring',
        'list_job_descriptions' => 'hiring', 'get_job_description' => 'hiring', 'create_job_description' => 'hiring',
        'assign_recruiters' => 'hiring', 'get_candidate' => 'hiring', 'review_candidate' => 'hiring',
        'save_interview' => 'hiring', 'set_interview_outcome' => 'hiring', 'mark_provisioning' => 'hiring',
        'issue_offer' => 'hiring', 'mark_candidate_accepted' => 'hiring', 'add_candidate_to_team' => 'hiring',
        'onboard_candidate' => 'hiring', 'get_onboard_options' => 'hiring', 'list_recruiters' => 'hiring',
        // Rewards (assignee/self surfaces; reviewer/payer tools gate by user-id in the tool)
        'get_my_reward_wallet' => 'rewards', 'list_my_reward_tasks' => 'rewards',
        'get_reward_task' => 'rewards', 'post_reward_task_update' => 'rewards',
        'submit_reward_task' => 'rewards', 'list_my_reward_withdrawals' => 'rewards',
    ];

    // Public name surfaced to Claude in tools/list. Stays snake_case
    // for consistency with the existing local stdio server.
    abstract public function name(): string;

    abstract public function description(): string;

    /**
     * JSON Schema (draft 2020-12 subset) describing the call arguments.
     * Surfaced verbatim to Claude in tools/list and also used by the
     * registry to do structural validation before dispatch.
     */
    abstract public function inputSchema(): array;

    /**
     * Run the tool. Receives validated args + the authenticated user.
     * Should return a JSON-serializable structure that's small enough
     * to fit under Claude's 150k char result limit.
     */
    abstract public function handle(array $args, User $user, Request $originalRequest): mixed;

    /**
     * Optional permission required to see/call this tool. Checked
     * against \App\Models\Role::roleHasPermission().
     */
    public function requiredPermission(): ?string
    {
        return null;
    }

    /**
     * Optional explicit list of role slugs allowed to see/call this
     * tool. ANY of these passes the gate.
     */
    public function allowedRoleSlugs(): ?array
    {
        return null;
    }

    /**
     * Optional list of user IDs (besides role/permission gates) allowed
     * to see/call this tool. Used for the per-user allowlists already
     * scattered across config/* (timesheet, bills, attendance, etc).
     */
    public function allowedUserIds(?User $user): ?array
    {
        return null;
    }

    /**
     * Optional portal feature this tool belongs to. Resolved from
     * FEATURE_MAP by tool name; override for a bespoke mapping. When set,
     * isAvailableTo() requires the user to have that feature. May be an array
     * of feature keys, in which case having ANY ONE of them passes the gate.
     */
    public function featureKey(): string|array|null
    {
        return self::FEATURE_MAP[$this->name()] ?? null;
    }

    /**
     * Final say. The default implementation evaluates featureKey +
     * allowedRoleSlugs + requiredPermission + allowedUserIds. Tools with
     * bespoke rules (e.g. "manager-only", which depends on subordinates)
     * override this.
     */
    public function isAvailableTo(User $user): bool
    {
        $extraIds = $this->allowedUserIds($user);
        if (is_array($extraIds) && in_array($user->id, $extraIds, true)) {
            return true;
        }

        // Per-login feature gate: keep the MCP catalog in lock-step with the
        // user's portal sidebar — if the tool maps to a feature they don't
        // have, hide it (and refuse the call). An array means "any one of".
        $feature = $this->featureKey();
        if ($feature !== null) {
            $required = is_array($feature) ? $feature : [$feature];
            if (! array_intersect($required, \App\Services\UserFeatureService::featuresFor($user))) {
                return false;
            }
        }

        $allowedRoles = $this->allowedRoleSlugs();
        if (is_array($allowedRoles) && $allowedRoles !== []) {
            if (! in_array($user->role, $allowedRoles, true)) {
                return false;
            }
        }

        $permission = $this->requiredPermission();
        if ($permission !== null) {
            return \App\Models\Role::roleHasPermission((string) $user->role, $permission);
        }

        return true;
    }
}
