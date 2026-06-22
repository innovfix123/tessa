<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Roles exempt from weekly manager reviews
    |--------------------------------------------------------------------------
    |
    | Users whose role slug appears in this list are never on any manager's
    | rating list (not even the CEO's). They still fill out their own weekly
    | review forms for the teams they manage.
    |
    */
    'exempt_roles' => ['coo', 'cmo', 'cfo'],

    /*
    |--------------------------------------------------------------------------
    | Overdue review lookback window
    |--------------------------------------------------------------------------
    |
    | How many past weeks to surface as "overdue" when a manager hasn't yet
    | submitted that week's work-quality ratings. Each overdue week appears as
    | its own card on the manager's portal dashboard until they submit, and
    | drives the weekday Slack nag for unrated past weeks. Set to 0 to disable.
    |
    */
    'overdue_lookback_weeks' => (int) env('REVIEW_OVERDUE_LOOKBACK_WEEKS', 1),

    /*
    |--------------------------------------------------------------------------
    | Waived review weeks
    |--------------------------------------------------------------------------
    |
    | Friday week_keys (Y-m-d) for which the work-quality review is waived for
    | EVERY manager: no overdue dashboard card, no Friday sign-off block, and no
    | Slack nag. Use this to retire a week that became un-completable — e.g. a
    | mid-week team reorg left several managers with a partial roster they can't
    | reconcile (any ratings already submitted are kept, just not required).
    | Reviews resume normally the following week.
    |
    | 2026-06-12 (week of Jun 8–12): waived after a same-day manager reorg left
    | 5 managers deadlocked on transferred-in reports. Resumes Fri 2026-06-19.
    |
    */
    'skip_weeks' => ['2026-06-12'],

    /*
    |--------------------------------------------------------------------------
    | New-hire grace exemptions
    |--------------------------------------------------------------------------
    |
    | User ids that bypass the new-hire grace in
    | ManagerWorkReview::rateableSubordinatesFor — they ARE reviewable in their
    | joining week instead of waiting until the week after they start. Use only
    | for a hire who joined at the very start of a review week and should be
    | rated immediately. Self-expiring: once a user's joining_date precedes the
    | week being rated, the grace clause no longer applies regardless.
    |
    | 91 (Priyadharshini): joined Mon 2026-06-15 (the exact start of that review
    | week); Ranjini (#27) should rate her this week, not next.
    |
    */
    'new_hire_grace_exempt_user_ids' => [91],
];
