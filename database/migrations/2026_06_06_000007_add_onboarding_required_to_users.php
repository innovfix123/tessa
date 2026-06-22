<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * New-hire onboarding gate flag (stage 9). Set true when a candidate is
 * onboarded into a real users row; while true (and the required profile fields
 * + mandatory documents aren't yet complete) the hire's portal is locked to My
 * Profile. Cleared when they finish onboarding → candidate flips to `hired`.
 * Defaults false so NO existing user is affected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('onboarding_required')->default(false)->after('employee_status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('onboarding_required');
        });
    }
};
