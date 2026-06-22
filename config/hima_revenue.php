<?php

return [
    /*
     * Per-user allow-lists for the Hima Revenue Sheet feature. Kept here
     * (not in the role/permission system) because access is scoped to
     * specific people, not roles.
     *
     * Live UI is currently a Google Sheet iframe (viewers only). The
     * editors list is preserved for the legacy interactive sheet
     * controller/JS (see HimaRevenueSheetController + hima-revenue-sheet.js)
     * which can be re-enabled by restoring the script load in
     * resources/views/dashboards/portal.blade.php.
     */
    'editors' => [3, 11],         // Nandha, Anirudh — kept for legacy API write access
    'viewers' => [1, 2, 4, 11, 3], // JP, Bala, Ayush, Anirudh, Nandha — see the embedded Google Sheet
];
