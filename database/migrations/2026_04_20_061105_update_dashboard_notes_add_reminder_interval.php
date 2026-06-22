<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dashboard_notes', function (Blueprint $table) {
            $table->string('title')->nullable()->change();
            $table->unsignedSmallInteger('reminder_interval')->nullable();
            $table->dropColumn('reminder_time');
        });
    }

    public function down(): void
    {
        Schema::table('dashboard_notes', function (Blueprint $table) {
            $table->string('title')->change();
            $table->string('reminder_time', 5)->nullable();
            $table->dropColumn('reminder_interval');
        });
    }
};
