<?php

return [
    // Who sees the in-portal "Employee Records" view. Two tabs, both embedded Google iframes:
    //   • ESIC Sheet         — the master Google Sheet (HR fills it by hand in Google).
    //   • Employee Documents — the master HR Drive folder (embeddedfolderview): per-employee
    //                          subfolders + files, populated by the Tessa-profile → Drive sync.
    // Both render only because the Sheet/folder are shared "anyone with the link".
    //
    // This view is all HR PII (bank / DOB / Aadhaar / PAN), so it is locked to the three named
    // people below ONLY — no role-based access (user decision 2026-06-16).
    'viewer_roles' => [],

    // The ONLY viewers.
    'viewer_ids' => [1, 45, 61], // JP, Meghana (HR), Akshara (HR Ops)
];
