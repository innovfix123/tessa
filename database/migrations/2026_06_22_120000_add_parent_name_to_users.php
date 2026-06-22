<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Parent / guardian name — rendered as "S/O" or "D/O <name>" on the
            // auto-filled employee NDA. Captured on Add Member, Team→Edit and the
            // employee's own My Profile.
            $table->string('parent_name', 150)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('parent_name');
        });
    }
};
