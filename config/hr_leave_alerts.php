<?php

return [
    /*
     * Users who see ALL approved leaves for "today" on their dashboard's
     * on-leave card, instead of only their direct reports. HR needs the
     * company-wide view so they can act on absences (cover plans, payroll,
     * etc.) without manually filtering the attendance sheet each morning.
     */
    'user_ids' => [
        45, // Meghana (HR)
        61, // Akshara J S Ponisha (HR)
    ],
];
