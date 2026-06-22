<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('agenda_templates')) {
            Schema::create('agenda_templates', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('agenda_template_items')) {
            Schema::create('agenda_template_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('template_id');
                $table->string('section_title');
                $table->text('point_question')->nullable();
                $table->integer('sort_order')->default(0);
                $table->timestamps();
                $table->foreign('template_id')->references('id')->on('agenda_templates')->onDelete('cascade');
            });
        }

        if (!Schema::hasColumn('meetings', 'agenda_template_id')) {
            Schema::table('meetings', function (Blueprint $table) {
                $table->unsignedBigInteger('agenda_template_id')->nullable()->after('attendees');
                $table->foreign('agenda_template_id')->references('id')->on('agenda_templates')->onDelete('set null');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('meetings', 'agenda_template_id')) {
            Schema::table('meetings', function (Blueprint $table) {
                $table->dropForeign(['agenda_template_id']);
            });
        }
        Schema::dropIfExists('agenda_template_items');
        Schema::dropIfExists('agenda_templates');
    }
};
