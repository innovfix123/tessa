<?php

return [
    // ── Route presets ────────────────────────────────────────────────────────
    // The quick-pick "from → to" options in the Add-Trip modal. Picking "Custom…"
    // lets the employee type a free-text from/to instead. Order = display order.
    'route_presets' => [
        ['from' => 'Home', 'to' => 'Office'],
        ['from' => 'Office', 'to' => 'Home'],
    ],

    // ── Google sync (via a connected admin's "Connect Google" OAuth token) ────
    // The travel ledger mirrors into the WRITER's own Google Drive — no service
    // account. The writer is the first user in writer_user_ids that has connected
    // Google AND granted the Drive + Sheets WRITE scopes: Shoyab (#32) primary,
    // Ayush (#4) fallback. Stays dormant-safe (no writer ⇒ trips just log locally,
    // no error) until one of them reconnects to grant those scopes — see
    // TravelLedgerWriter + TravelExpenseSyncService. Folder/sheet are auto-created
    // on first sync (find-or-create by name), so nothing needs provisioning.
    'writer_user_ids' => array_values(array_filter(array_map(
        'intval',
        explode(',', (string) env('GOOGLE_TRAVEL_WRITER_IDS', '32,4'))
    ))),

    // Drive folder tree the screenshots land in: {root}/{Month}/{Employee}/{Date}/file,
    // e.g. "Travel Expenses/June 2026/Bhoomika/17-06-2026/Bhoomika_Home_Office_17-06-2026_250.jpg".
    'root_folder_name' => env('TRAVEL_DRIVE_ROOT_NAME', 'Travel Expenses'),
    // The single master ledger spreadsheet (auto-created in the writer's Drive root folder).
    'ledger_sheet_name' => env('TRAVEL_LEDGER_SHEET_NAME', 'Travel Expenses — Master Ledger'),
    'ledger_sheet_tab' => env('TRAVEL_LEDGER_SHEET_TAB', 'Sheet1'),

    // OPTIONAL pins: set to reuse a specific pre-existing folder/sheet by id instead
    // of find-or-create-by-name. Default empty ⇒ auto-create + cache the resolved id.
    'drive_root_folder_id' => env('TRAVEL_DRIVE_FOLDER_ID', ''),
    'ledger_sheet_id' => env('TRAVEL_LEDGER_SHEET_ID', ''),

    // Comma-separated emails the root folder + ledger sheet are shared with (reader)
    // so the other accountant can open them even though they live in the writer's Drive.
    'share_emails' => array_values(array_filter(array_map('trim',
        explode(',', (string) env('TRAVEL_SHARE_EMAILS', ''))))),

    // Master-ledger columns — the ledger now has one sheet/tab PER EMPLOYEE (named after
    // them), so there is no "Employee" column here. "Description" maps to the trip note;
    // "Screenshot Link" holds the clickable Drive file URL.
    // Each tab ends with a bold Total row. Order = column order.
    'ledger_headers' => ['S.No', 'Date', 'Description', 'From', 'To', 'Amount', 'Screenshot Link'],
];
