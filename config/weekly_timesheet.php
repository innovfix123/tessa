<?php

return [
    /*
     * Users who do NOT fill a weekly timesheet. Everyone else who is active
     * fills one (including the other founders Bala/Nandha/Ayush). JP (#1, CEO)
     * is the only one excluded by default. Edit this list to change who's exempt.
     */
    'excluded_user_ids' => [
        1, // JP (CEO)
    ],

    /*
     * Extra users who can see the COMPANY-WIDE "Team" review (every employee's
     * weekly timesheet), beyond the HR/leadership roles that get it by role.
     * Mirrors config/hr_leave_alerts.php.
     */
    'reviewer_user_ids' => [
        45, // Meghana (HR)
        61, // Akshara J S Ponisha (HR)
    ],

    /*
     * Friday IST reminder times — documentation only. The live schedule lives
     * in routes/console.php (notify:weekly-timesheet).
     */
    'reminder_times' => ['16:30', '18:30'],
];
