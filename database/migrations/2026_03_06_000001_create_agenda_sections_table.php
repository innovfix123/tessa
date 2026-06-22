<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('agenda_sections')) {
            Schema::create('agenda_sections', function (Blueprint $table) {
                $table->id();
                $table->string('meeting_id', 50);
                $table->date('week_key');
                $table->string('title');
                $table->integer('sort_order')->default(0);
                $table->timestamps();
                $table->index(['meeting_id', 'week_key']);
            });
        }

        if (!Schema::hasColumn('discussion_points', 'section_id')) {
            Schema::table('discussion_points', function (Blueprint $table) {
                $table->unsignedBigInteger('section_id')->nullable()->after('sort_order');
                $table->index('section_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('agenda_sections');
        if (Schema::hasColumn('discussion_points', 'section_id')) {
            Schema::table('discussion_points', function (Blueprint $table) {
                $table->dropColumn('section_id');
            });
        }
    }
};
