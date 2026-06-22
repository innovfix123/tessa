<?php
// Per-user manual leave adjustments for HR's monthly attendance sheet.
// Shape: [user_id => ['YYYY-MM' => days]]
// Days are added to "Leaves" and subtracted from "Missed login" — never inflates working-day total.
// After editing, run bin/refresh-routes.sh to clear the config cache.
return [
    52 => [
        '2026-06' => 2,  // Fathima K P — 2 HR-credited leave days
    ],
];
