<?php

return [
    /*
     * Per-user allow-list for the "Sign-In Status" sidebar grid (color-coded
     * boxes per employee). Merges HR attendance viewers and CEO/team-status
     * viewers into one hand-picked list.
     */
    'user_ids' => [
        1,  // JP (CEO)
        32, // Shoyab (Accountant)
        45, // Meghana (HR)
        61, // Akshara J S Ponisha (HR)
        62, // Soundarya Balaraddi
    ],
    // Freelancers are not on the daily sign-in roster (Rohit, Yashasvi, etc.).
    'excluded_employment_types' => ['freelancer'],
];
