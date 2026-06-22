<?php

return [
    /*
     * Per-user allow-list for the "Team Status" sidebar grid (company-wide
     * per-employee Day/Week status: sign-ins, daily reports, pending work,
     * KRA). JP (CEO #1) also reaches it through his ceo role on the
     * frontend, but he is listed here too so the shared
     * /api/admin/employee-overview endpoint stays open to him once that
     * route moves off the role gate (see routes/api/admin.php).
     */
    'user_ids' => [
        1,  // JP (CEO) — keep listed so the endpoint stays open to him
        62, // Soundarya Balaraddi
    ],
];
