<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Karuna Behal (#54, Finance Intern) moves from Shoyab (#32) to report directly to
 * Ayush (#4, CFO). One field changes — reporting_manager_id — and everything else
 * follows automatically: leave approval (LeaveService->reportingManager), the Friday
 * Work-Quality review/rating (ManagerWorkReview::rateableSubordinatesFor), the Daily
 * Reports manager view, and KRA surfacing all retarget to Ayush. Role 17 (Accountant)
 * and designation "Finance Intern" stay.
 *
 * Looked up by id 54, NOT email (her email is finance@innovfix.in, not karuna@…).
 * The users table has timestamps disabled (no updated_at) — never write it here.
 */
return new class extends Migration
{
    private const KARUNA = 54;
    private const AYUSH = 4;
    private const SHOYAB = 32;

    public function up(): void
    {
        DB::table('users')->where('id', self::KARUNA)->update([
            'reporting_manager_id' => self::AYUSH,
        ]);
    }

    public function down(): void
    {
        DB::table('users')->where('id', self::KARUNA)->update([
            'reporting_manager_id' => self::SHOYAB,
        ]);
    }
};
