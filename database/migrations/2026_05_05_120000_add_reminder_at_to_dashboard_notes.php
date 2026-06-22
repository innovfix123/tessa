<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dashboard_notes', function (Blueprint $table) {
            $table->timestamp('reminder_at')->nullable()->after('reminder_interval');
        });
    }

    public function down(): void
    {
        Schema::table('dashboard_notes', function (Blueprint $table) {
            $table->dropColumn('reminder_at');
        });
    }
};
