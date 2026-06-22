<?php

namespace App\Mcp;

use App\Mcp\Tools as Tools;
use App\Models\User;
use Illuminate\Http\Request;

class McpToolRegistry
{
    /** @var array<string, Tool> */
    private array $tools = [];

    public function __construct()
    {
        foreach ($this->defaultTools() as $tool) {
            $this->tools[$tool->name()] = $tool;
        }
    }

    /** @return Tool[] */
    public function all(): array
    {
        return array_values($this->tools);
    }

    public function find(string $name): ?Tool
    {
        return $this->tools[$name] ?? null;
    }

    public function register(Tool $tool): void
    {
        $this->tools[$tool->name()] = $tool;
    }

    /**
     * Tool descriptors for the JSON-RPC tools/list reply, filtered by
     * what the given user is allowed to see.
     *
     * @return array<int, array{name:string,description:string,inputSchema:array}>
     */
    public function toolsForUser(User $user): array
    {
        $out = [];
        foreach ($this->tools as $tool) {
            if (! $tool->isAvailableTo($user)) {
                continue;
            }
            $out[] = [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'inputSchema' => $tool->inputSchema(),
            ];
        }
        return $out;
    }

    /**
     * Execute a tool by name. Re-checks the RBAC gate (defense in depth),
     * validates args against the inputSchema, and maps thrown exceptions
     * to the format expected by McpController.
     */
    public function call(string $name, array $args, User $user, Request $request): mixed
    {
        $tool = $this->find($name);
        if (! $tool) {
            throw new ToolException("Unknown tool: {$name}", 404);
        }
        if (! $tool->isAvailableTo($user)) {
            throw new ToolException("Tool '{$name}' is not available for your role.", 403);
        }
        $this->validate($tool->inputSchema(), $args, $name);
        return $tool->handle($args, $user, $request);
    }

    /**
     * Minimal JSON-Schema-ish validator. Walks the top-level "required"
     * + "properties" + "type" keys — enough to catch the common mistakes
     * (missing arg, wrong type) without bringing in a full json-schema
     * dependency. Bigger structural checks happen inside individual tools.
     */
    private function validate(array $schema, array $args, string $toolName): void
    {
        $required = $schema['required'] ?? [];
        if (is_array($required)) {
            foreach ($required as $field) {
                if (! array_key_exists($field, $args)) {
                    throw new ToolException("Tool '{$toolName}': missing required field '{$field}'.", 400);
                }
            }
        }
        $properties = $schema['properties'] ?? [];
        if (! is_array($properties)) {
            return;
        }
        foreach ($args as $key => $value) {
            if (! array_key_exists($key, $properties)) {
                // Permissive — unknown keys are dropped silently. Some
                // callers send extras like 'limit' on schemas that don't
                // declare it; not worth blocking.
                continue;
            }
            $expected = $properties[$key]['type'] ?? null;
            if ($expected === null) {
                continue;
            }
            $actual = self::jsonType($value);
            $allowed = is_array($expected) ? $expected : [$expected];
            if (! in_array($actual, $allowed, true) && ! ($actual === 'integer' && in_array('number', $allowed, true))) {
                throw new ToolException(
                    "Tool '{$toolName}': field '{$key}' should be {$this->describeType($allowed)}, got {$actual}.",
                    400,
                );
            }
        }
    }

    private static function jsonType(mixed $value): string
    {
        return match (true) {
            is_int($value) => 'integer',
            is_float($value) => 'number',
            is_bool($value) => 'boolean',
            is_string($value) => 'string',
            is_array($value) => array_is_list($value) ? 'array' : 'object',
            $value === null => 'null',
            default => 'unknown',
        };
    }

    private function describeType(array $types): string
    {
        return implode(' or ', $types);
    }

    /** @return Tool[] */
    private function defaultTools(): array
    {
        return [
            // Identity
            new Tools\WhoamiTool(),
            new Tools\ListMyPermissionsTool(),

            // Tasks
            new Tools\ListTasksTool(),
            new Tools\GetTaskTool(),
            new Tools\CreateTaskTool(),
            new Tools\UpdateTaskTool(),
            new Tools\DeleteTaskTool(),
            new Tools\ListMyActionNeededTool(),
            new Tools\ListTeamActionNeededTool(),
            new Tools\AddTaskCheckinTool(),
            new Tools\ExtendTaskDeadlineTool(),
            new Tools\ApproveTaskExtensionTool(),
            new Tools\VerifyTaskTool(),
            new Tools\ReopenTaskTool(),
            new Tools\CreateSubtaskTool(),
            new Tools\ListTaskBlockersTool(),
            new Tools\CreateTaskBlockerTool(),
            new Tools\RedirectTaskTool(),
            new Tools\NudgeTaskTool(),
            new Tools\EscalateTaskTool(),
            new Tools\GetTaskThreadTool(),
            new Tools\PostTaskThreadTool(),
            new Tools\InviteToTaskTool(),
            new Tools\DeleteSubtaskTool(),
            new Tools\DeleteTaskBlockerTool(),
            new Tools\DeleteRecurrenceTool(),
            new Tools\CreateRecurrenceTool(),
            new Tools\UpdateRecurrenceTool(),
            new Tools\ListRecurrencesTool(),

            // Checklists
            new Tools\AssignChecklistTool(),
            new Tools\ListChecklistsTool(),
            new Tools\ToggleChecklistItemTool(),
            new Tools\SaveChecklistItemNoteTool(),
            new Tools\DeleteChecklistTool(),
            new Tools\UpdateChecklistTool(),

            // Attendance / sign-in
            new Tools\SignInTool(),
            new Tools\SignOffTool(),
            new Tools\UndoSignInTool(),
            new Tools\UndoSignOffTool(),
            new Tools\GetSignInStatusTool(),

            // Logs (daily activity feed)
            new Tools\ListLogsTool(),
            new Tools\AddLogTool(),
            new Tools\DeleteLogTool(),

            // Claude Context (daily end-of-day summary, pushed by Claude itself)
            new Tools\LogClaudeContextTool(),
            new Tools\GetMyClaudeContextTool(),

            // Meetings
            new Tools\ListMeetingsTool(),
            new Tools\GetMeetingTool(),
            new Tools\ListActionItemsTool(),
            new Tools\SaveMeetingNoteTool(),
            new Tools\CreateMeetingTool(),
            new Tools\DeleteMeetingTool(),
            new Tools\RecordMeetingAttendanceTool(),
            new Tools\MeetingAttendanceSummaryTool(),
            new Tools\MeetingAttendanceOverviewTool(),
            new Tools\PendingMeetingNotesTool(),
            new Tools\AnalyzeMeetingScheduleTool(),
            new Tools\CreateScheduledMeetingTool(),
            new Tools\RescheduleMeetingTool(),
            new Tools\SkipScheduledMeetingTool(),
            new Tools\ListScheduledMeetingsTool(),
            new Tools\DeleteScheduledMeetingTool(),

            // Dashboard notes
            new Tools\ListDashboardNotesTool(),
            new Tools\CreateDashboardNoteTool(),
            new Tools\UpdateDashboardNoteTool(),
            new Tools\DeleteDashboardNoteTool(),
            new Tools\CreateReminderTool(),
            new Tools\ClearNotificationsTool(),

            // Reports
            new Tools\ListMyKrasTool(),
            new Tools\ListDailyReportsTool(),
            new Tools\UpdateDailyReportFieldTool(),
            new Tools\ListPendingWorkTool(),

            // Manager Work-Quality reviews (Friday ratings)
            new Tools\ListManagerReviewsTool(),
            new Tools\SubmitManagerReviewTool(),

            // KPI weekly reports (manager fills notes; admin manages definitions)
            new Tools\ListKpiReportPeopleTool(),
            new Tools\GetKpiReportTool(),
            new Tools\SaveKpiReportWeekTool(),
            new Tools\AddKpiReportItemTool(),
            new Tools\UpdateKpiReportItemTool(),
            new Tools\DeleteKpiReportItemTool(),

            // Profile / holidays
            new Tools\GetProfileTool(),
            new Tools\UpdateProfileTool(),
            new Tools\ListHolidaysTool(),

            // Creative category (team work-focus note)
            new Tools\GetCreativeCategoryTool(),
            new Tools\SetCreativeCategoryTool(),

            // Video handoffs (creator review loop)
            new Tools\ListVideoHandoffsTool(),
            new Tools\ReviewVideoHandoffTool(),
            new Tools\DeleteVideoHandoffTool(),

            // Weekly timesheet (company-wide Friday work record)
            new Tools\GetMyWeeklyTimesheetTool(),
            new Tools\SubmitWeeklyTimesheetTool(),

            // KPIs
            new Tools\ListKpiDefinitionsTool(),
            new Tools\ListUserKpisTool(),

            // HR
            new Tools\ListEmployeesTool(),
            new Tools\GetEmployeeTool(),
            new Tools\ListLeaveTypesTool(),
            new Tools\ListLeaveRequestsTool(),
            new Tools\RequestLeaveTool(),
            new Tools\ReviewLeaveTool(),
            new Tools\CancelLeaveTool(),
            new Tools\RequestLeaveCancellationTool(),
            new Tools\ReviewLeaveCancellationTool(),
            new Tools\ListTeamPendingLeavesTool(),
            new Tools\ListDepartmentsTool(),
            new Tools\ListDesignationsTool(),
            new Tools\CreateEmployeeTool(),
            new Tools\UpdateEmployeeTool(),
            new Tools\GetSalaryHistoryTool(),
            new Tools\GetPromotionHistoryTool(),
            new Tools\CreateDepartmentTool(),
            new Tools\CreateDesignationTool(),
            new Tools\ConfirmProbationTool(),
            new Tools\ExtendProbationTool(),
            new Tools\GetHrDashboardTool(),
            new Tools\ComputeSalaryTool(),

            // Letters
            new Tools\ListLettersTool(),
            new Tools\PreviewLetterTool(),
            new Tools\DeleteLetterTool(),
            new Tools\GenerateLetterTool(),

            // Bills
            new Tools\ListMyBillsTool(),
            new Tools\DeleteBillTool(),

            // Invoices (finance supplier-invoice tracking + reconciliation)
            new Tools\ListInvoicesTool(),
            new Tools\GetInvoiceReconciliationTool(),

            // Finance — revenue sheets
            new Tools\GetRevenuePayoutTool(),
            new Tools\HimaRevenueMonthsTool(),
            new Tools\GetHimaRevenueTool(),
            new Tools\UpdateHimaRevenueTool(),

            // Workforce OT payments (admin/accountant/ceo/cfo)
            new Tools\ListWorkforcePaymentsTool(),
            new Tools\WorkforceWeekSummaryTool(),
            new Tools\GetWorkforceUserWeekTool(),
            new Tools\WorkforceMarkPaidTool(),
            new Tools\WorkforceBulkMarkPaidTool(),

            // Agile
            new Tools\ListSquadsTool(),
            new Tools\GetSprintBoardTool(),
            new Tools\ListEpicsTool(),
            new Tools\ListStoriesTool(),
            new Tools\CreateStoryTool(),
            new Tools\UpdateStoryStatusTool(),
            new Tools\ListBugsTool(),
            new Tools\CreateBugTool(),
            new Tools\DeleteStoryTool(),
            new Tools\DeleteBugTool(),
            new Tools\ActivateSprintTool(),
            new Tools\ReviewSprintTool(),
            new Tools\CloseSprintTool(),
            new Tools\ReopenSprintTool(),
            new Tools\CreateSprintTool(),
            new Tools\UpdateSprintTool(),
            new Tools\CreateSquadTool(),
            new Tools\UpdateSquadTool(),
            new Tools\AddSquadMemberTool(),
            new Tools\RemoveSquadMemberTool(),
            new Tools\CreateEpicTool(),
            new Tools\UpdateEpicTool(),
            new Tools\DeleteEpicTool(),
            new Tools\UpdateStoryTool(),
            new Tools\UpdateBugTool(),
            new Tools\MoveBugTool(),
            // NOTE: list_projects intentionally NOT registered — GET /projects is a
            // pre-existing portal 500 (ProjectController::index withCount('releases')
            // references a missing App\Models\Release). Re-add once that is fixed.
            new Tools\CreateProjectTool(),
            new Tools\UpdateProjectTool(),
            new Tools\DeleteProjectTool(),
            new Tools\ListLabelsTool(),
            new Tools\CreateLabelTool(),
            new Tools\DeleteLabelTool(),
            new Tools\GetAgileDashboardTool(),
            new Tools\GetSprintBurndownTool(),
            new Tools\GetSprintCapacityTool(),

            // Support tickets
            new Tools\ListTicketsTool(),
            new Tools\CreateTicketTool(),

            // Hiring
            new Tools\ListCandidatesTool(),
            new Tools\ListJobDescriptionsTool(),
            new Tools\GetJobDescriptionTool(),
            new Tools\CreateJobDescriptionTool(),
            new Tools\AssignRecruitersTool(),
            new Tools\GetCandidateTool(),
            new Tools\ReviewCandidateTool(),
            new Tools\SaveInterviewTool(),
            new Tools\SetInterviewOutcomeTool(),
            new Tools\MarkProvisioningTool(),
            new Tools\IssueOfferTool(),
            new Tools\MarkCandidateAcceptedTool(),
            new Tools\AddCandidateToTeamTool(),
            new Tools\OnboardCandidateTool(),
            new Tools\GetOnboardOptionsTool(),
            new Tools\ListRecruitersTool(),
            new Tools\GetOnboardingStatusTool(),
            new Tools\CompleteOnboardingTool(),
            new Tools\ListHrApplicantsTool(),
            new Tools\UpdateHrApplicantTool(),

            // Marketing (ad reports + scripts)
            new Tools\ListMetaAdReportsTool(),
            new Tools\ListGoogleAdReportsTool(),
            new Tools\ListScriptsTool(),
            new Tools\GenerateScriptTool(),
            new Tools\SaveScriptToLibraryTool(),
            new Tools\DeleteScriptTool(),
            new Tools\ScriptStatsTool(),

            // Rewards
            new Tools\GetMyRewardWalletTool(),
            new Tools\ListMyRewardTasksTool(),
            new Tools\GetRewardTaskTool(),
            new Tools\PostRewardTaskUpdateTool(),
            new Tools\SubmitRewardTaskTool(),
            new Tools\ListMyRewardWithdrawalsTool(),

            // Rewards — reviewer / payer / pool-creator admin
            new Tools\CreateRewardTaskTool(),
            new Tools\UpdateRewardTaskTool(),
            new Tools\ApproveRewardTaskTool(),
            new Tools\RejectRewardTaskTool(),
            new Tools\ListManagedRewardTasksTool(),
            new Tools\ListPendingRewardWithdrawalsTool(),
            new Tools\MarkRewardWithdrawalPaidTool(),
            new Tools\ListMyRewardPoolsTool(),
            new Tools\CreateRewardPoolTool(),
            new Tools\ListPendingRewardPoolsTool(),
            new Tools\MarkRewardPoolPaidTool(),

            // Network leverage + misc
            new Tools\ListNetworkLeverageTool(),
            new Tools\AddNetworkLeverageTool(),
            new Tools\DeleteNetworkLeverageTool(),
            new Tools\ListAnnouncementsTool(),
            new Tools\ListManagerNotificationsTool(),
            new Tools\ListTessaChatsTool(),
            new Tools\TessaGrammarFixTool(),
            new Tools\GetDreamStoryTool(),
            new Tools\GetMorningQuoteTool(),
            new Tools\GetAiUsageTool(),

            // Archives — Tessa AI insight cards (Slack + Gmail)
            new Tools\ListSlackInsightsTool(),
            new Tools\SnoozeSlackInsightTool(),
            new Tools\CreateTaskFromSlackInsightTool(),
            new Tools\ClearSlackInsightsTool(),
            new Tools\ListGmailInsightsTool(),
            new Tools\SnoozeGmailInsightTool(),
            new Tools\CreateTaskFromGmailInsightTool(),
            new Tools\ClearGmailInsightsTool(),

            // Admin
            new Tools\AdminTasksOverviewTool(),
            new Tools\AdminDailyReportsOverviewTool(),
            new Tools\AdminMeetingsOverviewTool(),
            new Tools\ManagerRatingsOverviewTool(),
            new Tools\TessaRequestTool(),
        ];
    }
}
