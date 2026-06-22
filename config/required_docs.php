<?php

return [
    /*
     * Self-service "Required Documents".
     *
     * NDA + ESIC intern declaration: only employees who joined ON OR AFTER this
     * date fill these in the portal — download the template, sign/fill by hand,
     * scan, and upload back. Anyone who joined earlier (or has no joining date on
     * file) submitted them offline and never sees them.
     *
     * Form 11 (EPF declaration) is the EXCEPTION: it is company-wide for every
     * active, non-freelancer employee regardless of joining date, EXCEPT the
     * exempt ids below. That rule lives in EmployeeController::form11Applies().
     *
     * Config is cached in prod → run bin/refresh-routes.sh after editing.
     */
    'self_serve_from' => '2026-06-22',

    // Manual overrides by user id. force = always SHOW the section (e.g. a late
    // offline submitter who must now upload); exempt = always HIDE it (also
    // exempts the user from the company-wide Form 11 requirement).
    'force_user_ids' => [],
    // Leadership (JP, Bala, Nandha, Ayush) — exempt from all required documents,
    // including the company-wide Form 11.
    'exempt_user_ids' => [1, 2, 3, 4],
];
