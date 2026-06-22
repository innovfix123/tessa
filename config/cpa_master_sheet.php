<?php

return [
    /*
     * Anirudh's "Language wise CPA Master Sheet" — embedded on the dashboard as a
     * Google Sheet iframe (mirrors config/hima_revenue.php), plus the daily
     * auto-fill (sync:hima-cpa-sheet) that writes Hima admin "user report" metrics
     * into the sheet's non-formula columns.
     *
     * Access is per-user (not role-based): only these ids see the sidebar tab.
     */
    'viewers' => [1, 11], // JP, Anirudh

    // The daily auto-fill (sync:hima-cpa-sheet) writes through the FIRST of these
    // users that has connected Google with WRITE scopes (Drive + Sheets), via
    // OAuth (CpaSheetWriter -> GoogleUserService) — no service-account key needed.
    // Anirudh #11 first so edits are attributed to him (he must reconnect Google
    // once to grant write scopes); JP #1 as a fallback. Until one is write-capable
    // the sync no-ops (dormant-safe). The sheet is "anyone-with-link can edit", so
    // the writer needs only the spreadsheets scope, not explicit sharing.
    'writer_user_ids' => [11, 1], // Anirudh (attribution), JP (fallback)

    // The embedded sheet (monthly — update id/gid when a new month's sheet starts).
    'sheet_id'  => env('CPA_MASTER_SHEET_ID', '1Qj9mFK0-CR_gYHKCzyh480VIsaXD01U5FlZ5HruvWYI'),
    'gid'       => env('CPA_MASTER_SHEET_GID', '1484243062'),
    // Tab NAME (the gid's title) used by the Sheets values API for the auto-fill.
    // gid 1484243062 is the "All language" daily tab (confirmed from the sheet).
    'sheet_tab' => env('CPA_MASTER_SHEET_TAB', 'All language'),

    // In-Tessa language tab bar — Google hides the native sheet-tab strip when the
    // sheet is framed cross-domain, so we render our own buttons that swap the embed
    // to each tab's gid. Ordered; first = the default/landing tab. gids are
    // per-spreadsheet, so re-fetch them when the monthly `sheet_id` changes
    // (Sheets API: GET spreadsheets/{id}?fields=sheets.properties(title,sheetId)).
    'tabs' => [
        ['name' => 'All language',        'gid' => '1484243062'],
        ['name' => 'Tamil',               'gid' => '0'],
        ['name' => 'Malayalam',           'gid' => '1856167973'],
        ['name' => 'Kannada',             'gid' => '608915841'],
        ['name' => 'Telugu',              'gid' => '131617566'],
        ['name' => 'Hindi',               'gid' => '136263349'],
        ['name' => 'Bengali',             'gid' => '400777322'],
        ['name' => 'Week Target Tracker', 'gid' => '613912607'],
        ['name' => 'Sheet11',             'gid' => '721079808'],
    ],

    /*
     * --- Daily auto-fill (sync:hima-cpa-sheet) ---
     * Writes ONLY the mapped metric columns for a given date row; ad-spend and
     * formula columns are never touched. Safely no-ops until BOTH are provisioned:
     *   (1) `endpoint` + `token` below are set, and
     *   (2) a writer in `writer_user_ids` has connected Google with write scopes
     *       (OAuth via CpaSheetWriter — no service-account key needed).
     */
    'sync' => [
        // Hima admin "user reports" endpoint returning the daily metrics below.
        // Delivered by the Hima team 2026-06-21:
        //   GET {HIMA_APP_URL}/api/reports/users-report?date=YYYY-MM-DD
        // Set as a full URL, or a path appended to HIMA_APP_URL. Empty => sync no-ops.
        'endpoint' => env('HIMA_USER_REPORT_ENDPOINT', ''),

        // Token for the user-report endpoint. This endpoint is properly token-authed
        // with its OWN token (the old open endpoints ignored the token), so keep it
        // distinct from HIMA_ANALYTICS_TOKEN. Sent raw as the Authorization header
        // ("Bearer " is also accepted). Empty => falls back to the analytics token
        // in HimaAnalyticsService::getUserReport().
        'token' => env('HIMA_USER_REPORT_TOKEN', ''),

        // How the date is passed to the endpoint ('date' or 'from_to').
        'date_param_style' => env('HIMA_USER_REPORT_DATE_STYLE', 'date'),

        // Sheet column header  =>  dot-path into the Hima response. The endpoint
        // nests its metrics under `data`, so every path is prefixed `data.`.
        // `Registration` reads data.total_male_registered, which the endpoint does
        // NOT return directly — HimaCpaSheetSyncService derives it as
        // total_registration − total_female_registered (and prefers a real field if
        // the endpoint ever adds one).
        'field_map' => [
            'Total Paid User' => 'data.new_users_first_purchase',   // New Users First Purchase
            'Old paid user'   => 'data.old_users_first_purchase',   // Old Users First Purchase
            'Registration'    => 'data.total_male_registered',      // Total Male Registered (derived: total − female)
            'purchases'       => 'data.total_purchase_count',        // Total Purchase Count
            'Purchase Value'  => 'data.total_recharge',              // Total Recharge
            'D0 Revenue'      => 'data.day_0_revenue',               // Day 0 Revenue
            'Female Reg'      => 'data.total_female_registered',     // Total Female Registered
            'Voice Verified'  => 'data.verified_creators',           // Verified Creators
        ],
    ],
];
