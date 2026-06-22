<?php

return [
    /*
     * Per-user allow-lists for timesheet features. Kept here (not in the
     * role/permission system) because access is scoped to specific people,
     * not roles.
     */
    'self_log_user_ids' => [50, 64, 65],   // Suwetha (₹150/hr), Rachita (₹150/hr), Dhanalakshmi (₹150/hr) — Fida (41) removed from OT Timesheets 2026-06-15
    'tracker_user_ids'  => [1, 4],     // JP, Ayush — only people who view the tracker
];
