<?php

/*
|--------------------------------------------------------------------------
| Per-field Daily Report manager visibility
|--------------------------------------------------------------------------
|
| Routes individual daily-report KPI fields of a user to specific managers'
| Daily Reports tabs. Used for dual-managed people whose different fields
| belong to different managers.
|
| Format: [ owner_user_id => [ field_key => manager_user_id ] ]
|
| Semantics (enforced in DailyReportController::getFieldsForUser):
|   - The OWNER (viewing their own report) and ADMINS / non-routed viewers
|     always see ALL fields.
|   - A viewer who IS one of the routed managers for this owner sees only:
|       fields routed to them  +  fields not listed here at all.
|     i.e. a field routed to a DIFFERENT manager is hidden from them.
|
| A user not listed here is completely unaffected (all managers see all
| fields, the existing behaviour).
|
*/

return [
    // Dhanush (#13): Bala (#2) sees the Bangalore Connect conversion KPIs,
    // Sneha Sunoj (#5) sees only the daily work-summary box.
    13 => [
        'daily_work_summary' => 5,                  // → Sneha Sunoj
        'bangalore_registration_to_trial_pct' => 2, // → Bala
        'bangalore_trial_to_premium_pct_d7' => 2,   // → Bala
    ],
];
