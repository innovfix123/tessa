<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Users whose Daily Report does NOT gate end-of-day sign-off
    |--------------------------------------------------------------------------
    |
    | These users' KPIs are inherently *next-day* metrics — ad spend entered the
    | next morning from Google/Meta CSVs, "Total Paid Registered Users" auto-synced
    | at 06:15 the following day via `sync:hima-paid-users`, and CPA derived from
    | both. That makes the same-day report impossible to complete at their end of
    | day, so requiring it would block sign-off every weekday (Anirudh #11 was stuck
    | this way; see 2026-05-27). The Daily Report still appears on the Sign Off page
    | for them — it just no longer blocks the Sign Off button.
    |
    */

    'user_ids' => [
        11, // Anirudh — Marketing
    ],

];
