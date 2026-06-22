<?php

return [

    // employee_id => rating_manager_id
    // Overrides reporting_manager_id for the Friday Work-Quality Review only.
    // Leave approval still goes via reporting_manager_id.
    'rater_overrides' => [
        // Iksha #53 + Laxmi #23 (→ Ranjini #27, 2026_06_10_000001) and Gargi
        // Bisht #57 (→ Anindita #17, 2026_06_15_000006) now report to those
        // managers directly, so their ratings flow via reporting_manager_id —
        // no override needed. Kept as a key for future case-by-case reroutes.
    ],

];
