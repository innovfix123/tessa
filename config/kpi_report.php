<?php

return [

    /*
    |--------------------------------------------------------------------------
    | KPI Report — who sees it, who fills it
    |--------------------------------------------------------------------------
    |
    | The "KPI Report" section shows every active employee their own KPIs
    | (read-only) — name, monthly target, the manager's weekly tracking notes,
    | and the month-end AI summary. Managers fill the weekly notes on Fridays.
    |
    | Visibility/eligibility: everyone EXCEPT the technical_support team — except
    | Deeksha (id 25), who has her own scorecard in public/kpis.html.
    |
    */

    // Roles hidden from the KPI Report section / not eligible as subjects.
    'excluded_roles' => ['technical_support'],

    // ...except these user ids (included despite an excluded role). Deeksha (#25).
    'role_exception_user_ids' => [25],

    // Hard exclusions regardless of role (e.g. add 2,3,4 to drop leadership).
    'excluded_user_ids' => [],

    // View-all + manage KPI definitions (create/edit/remove KPI rows + targets).
    'admin_user_ids' => [1], // JP

    // Who fills a subject's weekly notes. Default = the subject's own
    // reporting_manager_id; override here for edge cases. JP (1) fills the
    // NULL-manager leadership: Bala (2), Nandha (3), Ayush (4).
    // Shape: [subject_user_id => filler_manager_id].
    'filler_overrides' => [
        2 => 1,
        3 => 1,
        4 => 1,
    ],

    // Per-user one-off window extensions: [filler_user_id => 'Y-m-d']. Lets that
    // manager keep editing this week's KPI notes through the END of that date,
    // beyond the normal Fri→Mon window. Self-expiring — has no effect once the
    // date passes, so it's safe to leave stale entries here. The week anchor on
    // the extension day still resolves to the just-ended Friday, so they edit the
    // right week. JP (#1) + Krishnan (#20) → Tue 2026-06-23 EOD.
    'window_extensions' => [
        1  => '2026-06-23', // JP
        20 => '2026-06-23', // Krishnan
    ],

];
