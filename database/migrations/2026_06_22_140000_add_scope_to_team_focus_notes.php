<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('team_focus_notes', function (Blueprint $table) {
            // Whether this focus line is for a single day or the whole (Mon–Sun)
            // week. Drives the viewer label ("Today's Focus" vs "This Week's
            // Focus") and the freshness/nag check in CreativeCategoryController.
            // Existing rows default to 'day' — preserves prior behaviour.
            $table->string('scope', 8)->default('day')->after('category');
        });
    }

    public function down(): void
    {
        Schema::table('team_focus_notes', function (Blueprint $table) {
            $table->dropColumn('scope');
        });
    }
};
