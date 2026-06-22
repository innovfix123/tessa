<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;

/*
 * Ranjini (#27) becomes reporting manager of QA interns Iksha H S (#53) and
 * Laxmi (#23), taking over from Yuvanesh (#34). reporting_manager_id alone
 * drives leave/permission approval, the pending queue + sidebar badge, Friday
 * ratings, and Daily Reports visibility — so this one flip moves all manager
 * duties to Ranjini. Yuvanesh keeps a leave-FYI dashboard card only
 * (config/leave_dashboard_cc.php). secondary_manager_id is left NULL on
 * purpose (a dotted line would re-grant Yuvanesh Daily Reports access).
 */
return new class extends Migration
{
    private const RANJINI = 27;
    private const YUVANESH = 34;
    private const REPORTS = [53, 23]; // Iksha H S, Laxmi

    public function up(): void
    {
        User::whereIn('id', self::REPORTS)
            ->where('reporting_manager_id', self::YUVANESH)
            ->update(['reporting_manager_id' => self::RANJINI]);
    }

    public function down(): void
    {
        // Only revert rows still pointing at Ranjini, so a later manual
        // reassignment isn't clobbered by a rollback.
        User::whereIn('id', self::REPORTS)
            ->where('reporting_manager_id', self::RANJINI)
            ->update(['reporting_manager_id' => self::YUVANESH]);
    }
};
