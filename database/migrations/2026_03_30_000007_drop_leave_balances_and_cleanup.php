<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('leave_balances');

        Schema::table('leave_types', function (Blueprint $table) {
            $table->dropColumn(['default_days_per_year', 'monthly_credit', 'max_consecutive_days', 'carry_forward_cap']);
        });
    }

    public function down(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            $table->unsignedInteger('default_days_per_year')->default(0)->after('slug');
            $table->decimal('monthly_credit', 4, 1)->unsigned()->default(0)->after('default_days_per_year');
            $table->unsignedInteger('max_consecutive_days')->default(0)->after('monthly_credit');
            $table->unsignedInteger('carry_forward_cap')->default(0)->after('max_consecutive_days');
        });
    }
};
