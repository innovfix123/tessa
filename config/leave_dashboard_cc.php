<?php

/*
|--------------------------------------------------------------------------
| Leave-approval dashboard "Team updates" FYI cards
|--------------------------------------------------------------------------
|
| When an employee's leave is approved (manual OR auto, ANY leave type), drop
| a ManagerNotification "Team updates" card on each listed manager's dashboard.
| Used for people whose approval moved to a new reporting manager but whose
| previous manager should still SEE their time off on the dashboard.
|
| Distinct from config/leave_notify_cc.php, which is a Slack-only FYI. The card
| is idempotent (re-approval updates the same row) and is cleared if the leave
| is later cancelled.
|
| Format: [ employee_user_id => [ manager_user_id, ... ] ]
|
*/

return [
    // Iksha H S (#53) & Laxmi (#23): approval moved to Ranjini (#27); former
    // manager Yuvanesh (#34) keeps a dashboard leave-FYI card only.
    53 => [34],
    23 => [34],
];
