<?php

return [
    /*
    |--------------------------------------------------------------------------
    | JP AI Command Center
    |--------------------------------------------------------------------------
    |
    | When enabled, JP (user_id=1) gets a collapsed sidebar (Dashboard + AI only)
    | and a natural-language command center. The AI understands intent and either
    | opens a section (read-only, JP can close back) or pre-fills a modal for him
    | to confirm. Every other user's portal is completely unchanged.
    |
    | Kill switch: set JP_AI_MODE=false in .env and run bin/refresh-routes.sh to
    | instantly revert JP to his normal full 44-section sidebar.
    |
    */
    'enabled' => env('JP_AI_MODE', false),

    // The single user this feature applies to. CEO / founder.
    'user_id' => 1,
];
