<?php

return [
    /*
     * Managers who receive a Slack DM whenever one of their DIRECT reports
     * (users whose reporting_manager_id == this id) signs in for the day on
     * Tessa. Purely reporting-line + allowlist driven — no role/project gate.
     * Add more manager ids here and re-run the config cache (bin/refresh-routes.sh).
     */
    'manager_ids' => [
        27, // Ranjini
    ],
];
