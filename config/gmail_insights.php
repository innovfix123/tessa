<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Gmail "important email" notifications
    |--------------------------------------------------------------------------
    |
    | The gmail:sync-important command fetches recent messages for each
    | allow-listed, Gmail-connected user, AI-classifies them, and persists the
    | important ones to `gmail_insights` for the dashboard Gmail tab.
    |
    */

    // Only these users are synced by the scheduler. Start with JP (1) for
    // validation, then add ids here to widen the rollout. `--all` overrides
    // this to every Google-connected active user.
    //
    // NOTE: a user only syncs once they've done "Connect Google" (full Gmail
    // scope) — plain Google sign-in stores no token. 45/61/34 are enrolled but
    // will produce nothing until they connect; the sync simply no-ops till then.
    'sync_user_ids' => [
        1,  // JP
        2,  // Bala
        32, // Shoyab
        45, // Meghana (HR)  — pending Gmail connect
        61, // Akshara (HR)  — pending Gmail connect
        67, // Smrithy (Tamil Support) — pending Gmail connect
        34, // Yuvanesh       — pending Gmail connect
        41, // Fida — internal-only Gmail tab (see internal_only_domains below)
        5,  // Sneha Sunoj (Ops Manager) — default AI-important mode (partnership / creator-ops / operational mail)
        27, // Ranjini — mention-only Gmail tab (see mention_only_user_ids below)
    ],

    // Dashboard shows insights created within this many days (older ones stay
    // in ?archive=1 history).
    'dashboard_days' => 7,

    // Gmail search query used to pull candidates. Pre-filters out promotions /
    // social / chats and bounds the window so we never classify ad/newsletter
    // bulk and keep the AI cost (and noise) low.
    'query' => 'newer_than:2d -category:promotions -category:social -in:chats',

    // Max messages fetched + classified per inbox per run (GoogleUserService
    // metadata batch caps at 20 anyway).
    'max_per_scan' => 20,

    // Emails whose subject contains any of these (case-insensitive substring)
    // are never surfaced — dropped before classification, so they cost no AI
    // and can't be flagged important. JP asked to drop reimbursement-request
    // mail; add keywords here as needed. (Applies to every synced inbox.)
    'exclude_subject_keywords' => ['reimbursement'],

    // Recipients whose Gmail tab shows ONLY mail from a company domain, with the
    // AI importance gate bypassed entirely (EVERY internal email is surfaced —
    // the opposite of the normal "AI-important only" behaviour). Enforced at SYNC
    // time: the Gmail query is narrowed to `from:{domain} in:inbox` and a strict
    // @{domain} address guard drops anything else. `in:inbox` also keeps the
    // user's own Sent mail out (they share the domain). These users get NO
    // user_filters entry, so read-time relevance filtering is a no-op for them
    // (they simply see all their stored rows — all internal by construction).
    'internal_only_domains' => [
        41 => 'innovfix.in', // Fida
    ],

    // Recipients with a FOCUSED Gmail tab: it surfaces ONLY emails that mention
    // their own name (from users.name, matched on subject + preview) — PLUS any
    // categories in `mention_plus_categories` below. The generic AI-importance gate
    // is bypassed entirely (the name-focused analogue of internal_only_domains).
    // Enforced at SYNC time in syncForUser's store loop: an email is stored only if
    // it mentions the name OR matches an opted-in category; nothing else. These
    // users get NO user_filters entry, so read-time filtering is a no-op (every
    // stored row is a name-mention or opted-in category by construction).
    'mention_only_user_ids' => [
        27, // Ranjini
    ],

    // For mention_only recipients: ALSO surface emails the AI tags with any of
    // these categories, even when they don't mention the user's name — e.g. meeting
    // invites / calendar events. Keeps the tab focused: {name-mentions} ∪ {these
    // categories}. Categories must match the classify() set (Meeting, Calendar,
    // Document, Client, Approval, Project, Security, Billing, Alert, Operational,
    // Other).
    'mention_plus_categories' => [
        27 => ['Meeting', 'Calendar'], // Ranjini — also wants meeting/calendar emails
    ],

    // Per-recipient relevance filters for the LIVE dashboard Gmail tab. A user
    // listed here sees ONLY emails matching:
    //   category IN `categories`  OR  sender matches a configured pattern.
    //
    // `force_sender_user_ids`: internal people (resolved to their @ email at
    //   runtime). These ALSO bypass the AI importance gate at sync time, so
    //   EVERY mail from them is stored & shown.
    // `sender_patterns`: external senders (raw, case-insensitive substrings).
    //   These match at read time only — still subject to AI importance (so only
    //   "important" provider mail surfaces).
    //
    // Filters apply to the dashboard only — the ?archive=1 history stays
    // unfiltered so nothing is lost. Users NOT listed here see all their
    // important emails (the default).
    'user_filters' => [
        // JP — meeting invitations / events + server-infra & cybersecurity
        // alerts. Deliberately drops operational / billing / HR mail.
        1  => ['categories' => ['Meeting', 'Calendar', 'Security', 'Alert']],

        // Bala — meeting reminders + document requests.
        2  => ['categories' => ['Meeting', 'Calendar', 'Document']],

        // HR (Meghana, Akshara) — document requests + ALL mail from JP / Ayush
        // / Shoyab.
        45 => ['categories' => ['Document'], 'force_sender_user_ids' => [1, 4, 32]],
        61 => ['categories' => ['Document'], 'force_sender_user_ids' => [1, 4, 32]],
        67 => ['categories' => ['Document'], 'force_sender_user_ids' => [1, 4, 32]],

        // Shoyab — ONLY mail from JP / Ayush / HR (Meghana, Akshara). Nothing else.
        32 => ['categories' => [], 'force_sender_user_ids' => [1, 4, 45, 61]],

        // Yuvanesh — important mail from infra/payment providers + critical
        // infra/payment alerts. (Providers are NOT force-included — important only.)
        34 => [
            'categories'      => ['Security', 'Alert', 'Billing'],
            'sender_patterns' => ['amazonaws', 'aws.amazon', 'digitalocean', 'cashfree', 'paysprint'],
        ],
    ],
];
