<?php

return [
    /*
     * Daily Reports rollback (2026-06-18).
     *
     * The Daily Reports tab, its sign-off gate, its dashboard "pending" card and
     * its KRA discipline component now apply ONLY to the people resolved below.
     * For everyone else the daily report is gone — their equivalent end-of-day
     * obligation is the daily "Claude Context" summary (config/claude_context.php),
     * which already holds sign-off and now also drives their pending card + KRA.
     *
     * Resolved in App\Support\DailyReportsAccess::enabledFor(). Gate EVERY
     * daily-report surface through that helper so the tab, the sign-off gate, the
     * pending card and KRA stay in lock-step.
     */

    // Explicit individuals who keep Daily Reports regardless of reporting line.
    'user_ids' => [
        32, // Shoyab (Finance / payroll)
        91, // Priyadharshini (#91) — Content Moderation (Hima)
    ],

    // Who may EXPORT Daily Reports to Excel (custom date range, all people they
    // can see). Deliberately decoupled from tab access above — being able to SEE
    // the tab does not grant export. Surfaced to the frontend as
    // config.canExportDailyReports and re-checked in DailyReportController::export().
    'export_user_ids' => [
        32, // Shoyab (Finance / payroll)
    ],

    // These managers AND their entire reporting subtree keep Daily Reports.
    // Krishnan (#20) => the whole Content team (Anaz, Sooraj, Kishore + his
    // creators, Sivaranjani, …). Listed by manager so new content hires under
    // them are covered automatically, without hard-coding every id.
    'manager_ids' => [
        20, // Krishnan — Content team
    ],

    /*
     * From this IST date, the daily "Claude Context" summary is what counts
     * toward the KRA discipline score (and the dashboard pending card) for
     * everyone NOT in the allow-list above. Before it, Daily Reports were the
     * rule, so Claude Context is not scored / flagged retroactively.
     */
    'claude_context_kra_from' => '2026-06-18',
];
