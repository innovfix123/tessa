<?php

/*
|--------------------------------------------------------------------------
| Per-user KRA effective-from overrides
|--------------------------------------------------------------------------
|
| When a user's onboarding / training spans days that shouldn't count
| against (or for) their KRA score, pin the KRA scoring window to this
| date instead of the user's actual joining_date. Applies to:
|   - discipline (sign-ins, sign-offs, KPI reports, task check-ins)
|   - manager-review new-hire grace (rateableSubordinatesFor)
|
| Format: [user_id => 'Y-m-d']
|
| Keep this list small — overrides are case-by-case calls, not a system
| default. Once an override date is in the past and the user is fully
| ramped, the row can be removed (existing scores are recomputed from
| current config on next render).
|
*/

return [
    59 => '2026-05-18', // Bhuvan Prasad  (joined 2026-05-13; KRA from week of 5/18 per Fida)
    60 => '2026-05-18', // Bhoomika        (joined 2026-05-14; KRA from week of 5/18 per Fida)
];
