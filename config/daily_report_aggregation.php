<?php

return [
    // Managers listed here never roll up their direct reports' narrative
    // (textarea) Daily Report entries into their own tab. Their tab — like
    // everyone else's — shows only their own entries and their own count;
    // they review the team via the individual person tabs at the top of
    // Daily Reports instead of a merged view.
    //
    // Scope: textarea fields only. File-upload roll-ups are unaffected, and
    // managers NOT listed here keep the normal team-total aggregation
    // (e.g. Krishnan's "Ad Scripts Written", Shoyab's "Blockers").
    'no_textarea_aggregation_user_ids' => [
        41, // Fida
    ],
];
