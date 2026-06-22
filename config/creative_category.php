<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Creative-category setters
    |--------------------------------------------------------------------------
    |
    | Users who set a daily "creative category" / work-focus note for their
    | team — shown to their direct reports on the dashboard and as a modal
    | right after sign-in. Each viewer sees the note of THEIR OWN
    | reporting_manager_id, but only if that manager is listed here.
    |
    | Currently: Krishnan (20) and Kishore (51). Add a manager's id here to
    | give them the input box; their direct reports automatically become
    | viewers. Config is cached in prod — run bin/refresh-routes.sh after edit.
    |
    */
    'setter_user_ids' => [20, 51],
];
