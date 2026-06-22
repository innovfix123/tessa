<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // `meetings.portal` was an ENUM listing only a subset of roles, but the
        // application treats EVERY role with the `meeting.access` permission as a
        // valid meetings portal (MeetingController::normalizePortal). Roles that
        // gained meeting.access but were never added to the ENUM
        // (team_lead_operations, hr_operations, customer_support_executive, hr,
        // accountant, qa_analyst, …) therefore had a broken meetings portal:
        // under STRICT_TRANS_TABLES, INSERTing a meeting with their portal was
        // rejected ("Data truncated for column 'portal'"), so "Add Meeting"
        // silently failed (e.g. Nitha Sheri / team_lead_operations).
        //
        // Convert to VARCHAR so the in-app meeting.access gate is the single
        // source of truth and no future role hits this ENUM landmine. All
        // currently-stored portal values fit unchanged.
        DB::statement('ALTER TABLE meetings MODIFY portal VARCHAR(64) NOT NULL');
    }

    public function down(): void
    {
        // Restore the original ENUM. NOTE: this fails if any meeting now uses a
        // portal outside the original set (e.g. team_lead_operations); remap
        // those rows to a listed portal before rolling back.
        DB::statement("ALTER TABLE meetings MODIFY portal ENUM('ops','ceo','coo','cmo','cfo','marketing','product_manager','tech_lead','full_stack_developer','content_lead','gen_ai_developer') NOT NULL");
    }
};
