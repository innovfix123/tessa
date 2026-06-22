<?php

/*
|--------------------------------------------------------------------------
| Leave-approval FYI recipients (dotted-line managers)
|--------------------------------------------------------------------------
|
| When a user's leave is approved (manual approve OR auto-approve), also send
| an FYI Slack DM to these extra user ids. Used for dual-managed people whose
| dotted-line manager should know about their time off even though approval
| runs through the primary reporting_manager_id.
|
| Deliberately NOT tied to secondary_manager_id: several ops staff already
| have a secondary manager and we don't want to FYI-spam them.
|
| Format: [ employee_user_id => [ cc_user_id, ... ] ]
|
*/

return [
    // Dhanush (#13): leaves approved by Bala — FYI Sneha Sunoj (#5).
    13 => [5],
];
