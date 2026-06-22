<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Users entirely excluded from the KRA system
    |--------------------------------------------------------------------------
    |
    | These users do not receive KRA ratings at all. They are removed from the
    | Team KRAs table, the CEO user picker, the admin daily/weekly KRA column,
    | their own scorecard, and the sidebar "Avg KRA" widget. Nothing about KRA
    | is computed or shown for them.
    |
    | Currently: JP (1, CEO), Bala (2, COO), Nandha (3, CMO), Ayush (4, CFO).
    |
    */

    'excluded_user_ids' => [1, 2, 3, 4],

];
