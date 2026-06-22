<?php

return [
    /*
     * Personal Calendar section — a month-grid view backed by the user's own
     * Google Calendar (NOT a local table). Users in the allow-list below get the
     * "Calendar" sidebar tab, the dashboard "Calendar" notification tab, and the
     * daily morning Slack DM listing that day's notes (notify:calendar-notes).
     *
     * The feature is dead weight until the user has connected Google with the
     * calendar scope (Connect Google in My Profile → Integrations). It degrades
     * gracefully to a "connect Google" prompt when they haven't.
     *
     * Access is per-user (not role-based): only these ids see any of it.
     */
    'viewer_user_ids' => [
        27, // Ranjini (QA Analyst)
    ],

    // Daily morning Slack DM of today's notes. Scheduled at 9:05 IST on purpose:
    // the Slack quiet window is 10pm→9am, so an earlier send would be silently
    // dropped. Set false to keep the in-app card but stop the DM.
    'slack_reminder_enabled' => env('CALENDAR_SLACK_REMINDER', true),

    // How many days ahead the dashboard "Calendar" card looks (today inclusive).
    'dashboard_lookahead_days' => 7,
];
