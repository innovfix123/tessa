<?php

return [
    // ── Bills: a default for ALL employees ───────────────────────────────────
    // Bills (company invoices / subscriptions / agency payments) are open to
    // everyone — any signed-in employee gets the sidebar "Bills" section and the
    // "My Bills" submit tab. Reimbursements + Travel allowance stay invite-only
    // (the allow-lists below). Flip this to false to fall back to the
    // bill_submitter_ids allow-list instead.
    'bills_open_to_all' => true,

    // ── Submitter allow-lists ────────────────────────────────────────────────
    // Who can raise each request type. Seed these AFTER the group confirmation
    // (people reply "Bills" / "Reimburse"). Admins below can always submit both
    // regardless of these lists.
    // Seeded from the group poll (2026-06-02). "Both" voters appear in both lists.
    // NOTE: bill_submitter_ids is now only consulted when bills_open_to_all is
    // false — Bills are open to all by default (above). Kept for that fallback.
    'bill_submitter_ids' => [
        1,   // JP
        2,   // Bala
        3,   // Nandha
        4,   // Ayush (also admin)
        5,   // Sneha Sunoj
        20,  // Krishnan ("Krish")
        32,  // Shoyab ("Jo Jo"; also admin)
        34,  // Yuvanesh
        45,  // Meghana
        61,  // Akshara (HR/BA)
    ],
    // DEPRECATED for gating (2026-06-08): reimbursement access is now driven by
    // reimbursement_category_user_ids below (the per-category lists). This list
    // no longer decides who sees the Reimbursements tab — kept only for the
    // sidebar-union / MCP references and historical reference.
    'reimbursement_submitter_ids' => [
        // "Both" voters
        1,   // JP
        2,   // Bala
        3,   // Nandha
        4,   // Ayush (also admin)
        32,  // Shoyab (also admin)
        34,  // Yuvanesh
        45,  // Meghana
        61,  // Akshara (HR/BA)
        // "Reimburse" voters
        41,  // Fida Taneem
        38,  // Maari ("Mari Muthu")
        37,  // Perumal ("Siva Perumal")
        40,  // Disha
        55,  // Swapna M
        54,  // Karuna Behal        [intern]
        19,  // Sooraj
        12,  // Tamil Arasan
        23,  // Laxmi ("Lakshmi")   [intern]
        59,  // Bhuvan Prasad       [intern]
        60,  // Bhoomika            [intern]
        62,  // Soundarya Balaraddi [intern]
        21,  // Tiyasa
        46,  // Irisha
        27,  // Ranjini (AI-QA) — added 2026-06-04
    ],

    // ── Reimbursement categories ─────────────────────────────────────────────
    // A reimbursement MUST pick exactly one of these (mandatory dropdown in the
    // submit/edit modal). Bills + Travel keep their free-text category. The
    // stored value is the label itself, so it reads cleanly in Records/exports.
    'reimbursement_categories' => [
        'PG reimbursement',
        'Room rent reimbursement',
        'Wifi reimbursement',
        'SIM recharge reimbursement',
    ],
    // The Wi-Fi category carries a HARD ceiling — the monthly broadband budget
    // is ₹700, so a Wi-Fi claim above this is rejected before the file is even
    // stored. Must match one of reimbursement_categories above exactly.
    'wifi_reimbursement_category' => 'Wifi reimbursement',
    'wifi_reimbursement_cap' => 700,

    // ── Per-category visibility = reimbursement access ───────────────────────
    // These per-category allow-lists are now the COMPLETE definition of who can
    // claim reimbursements: a reimbursement is always one of the 3 categories
    // above, so being on a list here is what grants the Reimbursements tab — and
    // you only ever see the category(ies) you're listed under. Someone on NO list
    // can't submit any reimbursement (their past claims stay). A category with no
    // key here would fall back to "open to all"; all 3 are listed, so nothing is
    // open. Keys MUST match a label in reimbursement_categories exactly.
    // Confirmed by JP 2026-06-08 (WhatsApp lists).
    'reimbursement_category_user_ids' => [
        // Wi-Fi reimbursement — shown ONLY to these 16 employees.
        'Wifi reimbursement' => [
            5,   // Sneha Sunoj
            13,  // Dhanush
            17,  // Anindita
            25,  // Deeksha
            26,  // Gousia
            28,  // Reshma
            35,  // Rishabh ("Rishab")
            48,  // Anjali Bhatt
            50,  // Suwetha S
            51,  // Kishore Prabakaran ("Kishore")
            55,  // Swapna M
            56,  // Y Nehal ("Nehal")
            57,  // Gargi Bisht
            58,  // Sivaranjani N ("Sivaranjini N")
            64,  // Rachita
            65,  // Dhanalakshmi
        ],
        // PG reimbursement — Yuvanesh + Shoyab.
        'PG reimbursement' => [
            34,  // Yuvanesh
            32,  // Shoyab ("Jo Jo"; also admin)
        ],
        // Room rent ("home") reimbursement — Saran + Bala.
        'Room rent reimbursement' => [
            44,  // Saran
            2,   // Bala
        ],
        // SIM recharge — the support team (technical_support + customer_support_executive
        // roles): the Hima language-support agents + the OnlyCare CS executives. Uncapped
        // (no per-claim ceiling / once-a-month lock — unlike Wi-Fi). Added 2026-06-16.
        'SIM recharge reimbursement' => [
            25,  // Deeksha (Kannada Support)
            26,  // Gousia (Telugu Support)
            28,  // Reshma (Malayalam Support)
            48,  // Anjali Bhatt (Bengali Support)
            50,  // Suwetha S (Technical Support)
            67,  // Smrithy (Tamil Support)
            64,  // Rachita (Customer Support Executive — OnlyCare)
            65,  // Dhanalakshmi (Customer Support Executive — OnlyCare)
        ],
    ],

    // ── Travel allowance ─────────────────────────────────────────────────────
    // Separate "Travel Allowance" tab + type. Eligible claimants upload a
    // payment screenshot daily; a HARD monthly cap (below) blocks new claims
    // once their pending + paid travel claims for the calendar month (IST)
    // reach it. A rejected/cancelled claim frees the room back up. NOT
    // auto-granted to admins — add ids explicitly. Seeded 2026-06-02 = the
    // reimbursement claimants from the group poll (everyone gets the same cap).
    'travel_allowance_user_ids' => [
        41,  // Fida Taneem
        38,  // Maari ("Mari Muthu")
        37,  // Perumal ("Siva Perumal")
        40,  // Disha
        54,  // Karuna Behal
        19,  // Sooraj
        12,  // Tamil Arasan
        23,  // Laxmi ("Lakshmi")
        59,  // Bhuvan Prasad
        60,  // Bhoomika
        62,  // Soundarya Balaraddi
        21,  // Tiyasa
        46,  // Irisha
        61,  // Akshara (HR/BA)
        27,  // Ranjini (AI-QA) — added 2026-06-04
        53,  // Iksha H S (QA Intern) — added 2026-06-10
        45,  // Meghana (HR/BA) — added 2026-06-19
    ],
    // Hard ceiling per person per calendar month, in INR.
    'travel_monthly_cap' => 3000,

    // ── Admins ───────────────────────────────────────────────────────────────
    // Share ONE Bills section: the Pay Queue (mark paid / reject) + the Records
    // ledger for accounts, and they can submit their own requests too. A payer
    // can never mark their OWN request paid — the other admin settles it.
    // Ayush (CFO, #4) + Shoyab (Accountant, #32).
    'admin_user_ids' => [4, 32],

    // Who gets the Slack "new request pending" DM. Both admins.
    'approval_heads_up' => [4, 32],
];
