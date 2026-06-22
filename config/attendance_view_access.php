<?php

return [
    /*
     * Per-user allow-list for the "Attendance" sidebar view (HR/admin
     * read-only roster of who signed in, who's on leave, and who's missing
     * for any given date). Lives outside the role/permission system because
     * the audience is a hand-picked group (HR + Accountant) rather than a
     * whole role tier.
     */
    'user_ids' => [
        32, // Shoyab (Accountant)
        45, // Meghana (HR)
        61, // Akshara J S Ponisha (HR)
        62, // Soundarya Balaraddi
    ],
];
