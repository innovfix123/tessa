<?php

return [
    // ── HR / management roles ────────────────────────────────────────────────
    // Roles that see the FULL Hiring pipeline (every JD), can assign JDs to
    // freelance recruiters, and (later phases) run the HR round + provisioning.
    // Mirrors the HR/management allowlist used across the HR controllers
    // (EmployeeController::ALLOWED_ROLES etc.). Re-checked in HiringController —
    // the sidebar flag in DashboardController is convenience only.
    'roles' => [
        'hr',
        'hr_operations',
        'ceo',
        'coo',
        'cmo',
        'cfo',
        'business_analyst',
    ],

    // ── Panel members ────────────────────────────────────────────────────────
    // Extra INDIVIDUAL users (beyond the roles above) who may CREATE job
    // descriptions and act as the panel member for them — hiring managers /
    // leads who don't hold an HR/management role. Anyone who has already
    // authored a JD is auto-included by DashboardController regardless of this
    // list, so this only needs the FIRST-time creators. Add ids as needed.
    'panel_member_ids' => [
        5,   // Sneha Sunoj (Ops — Hima PM, acting)
        20,  // Krishnan (Content Lead)
        34,  // Yuvanesh (Tech Lead — All Apps + Hima Strategist)
        41,  // Fida Taneem (Lead AI Engineer)
    ],

    // ── Notifications ────────────────────────────────────────────────────────
    // Who gets the in-app + Slack heads-up when a new JD is created (so HR can
    // assign it to a recruiter). HR leads by default.
    'jd_notify_user_ids' => [
        45,  // Meghana (HR)
        61,  // Akshara (HR Operations)
    ],

    // ── Account provisioning (Phase 4) ───────────────────────────────────────
    // On "Send to Tessa" these two get pinged to create the new hire's accounts,
    // and they (or HR) tick each task done. Single source of truth for the
    // provisioning assignees.
    'tessa_provisioner_id' => 41,      // Fida — creates the Tessa login
    'workspace_provisioner_id' => 34,  // Yuvanesh — creates the Gmail + Slack accounts

    // ── Offer-acceptance detection (Phase 2) ─────────────────────────────────
    // HR inboxes scanned for a candidate's "I accept" reply by
    // `hiring:detect-offer-acceptances`, AND the people pinged when one lands.
    // The offer must have been SENT from one of these inboxes (the portal can't
    // send mail — letters go out via a Gmail compose deep-link), so the reply
    // comes back here. Auto-detect no-ops for any of these who haven't done
    // "Connect Google" (full Gmail scope) — see config/gmail_insights.php.
    'offer_sender_ids' => [
        45, // Meghana (HR)
        61, // Akshara (HR Operations)
    ],
];
