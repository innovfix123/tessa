<?php

/*
|--------------------------------------------------------------------------
| Manager-keyed leave dashboard FYI cards
|--------------------------------------------------------------------------
|
| Like config/leave_dashboard_cc.php, but keyed by the leaver's CURRENT
| reporting_manager_id instead of by individual employee id. When ANYONE who
| reports to a listed manager has leave approved (manual OR auto, ANY type), a
| "Team updates" FYI card is also dropped on each mapped FYI manager's
| dashboard.
|
| This is dynamic: it follows team changes automatically, so a skip-level
| manager keeps visibility over a whole team without having to list each report
| (or remember to add new joiners). Approval is NOT affected — the FYI managers
| only SEE the leave; the direct reporting manager still approves it.
|
| The card is idempotent (re-approval updates the same row), is cleared if the
| leave is cancelled, and the approving reporting manager is never FYI'd
| themselves. Distinct from config/leave_notify_cc.php (Slack-only FYI).
|
| Format: [ reporting_manager_id => [ fyi_manager_user_id, ... ] ]
|
*/

return [
    // Rishabh's (#35) reports → skip-level FYI to Yuvanesh (#34). Approval stays
    // with Rishabh; Yuvanesh only sees the dashboard card.
    35 => [34],
];
