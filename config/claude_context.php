<?php

return [
    /*
     * Users who see EVERY employee's daily "Claude context" (the JP overview),
     * instead of only their own. Mirrors config/hr_leave_alerts.php. Re-checked
     * server-side in ClaudeContextController and drives the dashboard
     * 'claudeContextOverview' flag for the All / Team sub-tab.
     */
    'overview_user_ids' => [
        1,  // JP (CEO)
        41, // Fida
    ],

    /*
     * Users exempt from the Claude Context sign-off gate. By default empty —
     * everyone with the 'claude_context' feature must log their summary before
     * signing off. Stripped portals (freelance_recruiter) are auto-exempt via
     * the feature check and don't need to be listed here.
     */
    'signoff_excluded_user_ids' => [],
];
