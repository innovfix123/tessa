<?php

return [
    /*
     * Daily-report entries (creative_uploads — scripts, uploads, and textarea
     * fields) belonging to these users are OWNER-ONLY: in the Daily Reports tab
     * a manager/admin can VIEW the entry in the popup but gets no Edit/Delete —
     * only the person themselves may change or remove their own entries.
     *
     * So a manager can't edit/delete their reports' entries. Currently the
     * content-creation team (Krishnan's group) and the AI-engineering team
     * (Fida's reports). This does NOT touch the add-entry compose box (already
     * owner-only) or teams not listed here.
     *
     * Enforced in BOTH layers:
     *   - backend: CreativeUploadController (handleUpload/handleSaveText/handleDelete)
     *   - frontend: the entry popup in public/js/portal.js (renderRead)
     *     via config.dailyReportOwnerOnlyUserIds injected by DashboardController.
     */
    'user_ids' => [
        20, // Krishnan — Content Lead
        21, // Tiyasa — Content Creator
        40, // Disha — Content Creator
        49, // Haripriya — Content Creator
        51, // Kishore Prabakaran — Content Creator
        52, // Fathima K P — Content Creator
        56, // Y Nehal — Content Creator
        58, // Sivaranjani N — Content Creator
        18, // Anaz — Video Editor
        19, // Sooraj — Graphic Designer
        57, // Gargi Bisht — Social Media

        // AI-engineering team (report to Fida #41)
        59, // Bhuvan Prasad — AI Intern
        60, // Bhoomika — AI Intern
        62, // Soundarya Balaraddi — AI Intern
    ],
];
