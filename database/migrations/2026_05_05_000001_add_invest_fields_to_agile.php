<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stories', function (Blueprint $table) {
            $table->text('technical_notes')->nullable()->after('acceptance_criteria');
            $table->enum('moscow', ['must', 'should', 'could', 'wont'])->nullable()->after('priority');
            $table->enum('business_value', ['low', 'medium', 'high'])->nullable()->after('moscow');
            $table->index('moscow');
        });

        Schema::table('sprints', function (Blueprint $table) {
            $table->unsignedSmallInteger('capacity_hours')->nullable()->after('velocity');
            $table->text('review_notes')->nullable()->after('capacity_hours');
            $table->json('retrospective_notes')->nullable()->after('review_notes');
        });

        Schema::table('squads', function (Blueprint $table) {
            $table->text('definition_of_ready')->nullable()->after('description');
            $table->text('definition_of_done')->nullable()->after('definition_of_ready');
            $table->unsignedTinyInteger('wip_limit_per_user')->nullable()->after('definition_of_done');
        });
    }

    public function down(): void
    {
        Schema::table('stories', function (Blueprint $table) {
            $table->dropIndex(['moscow']);
            $table->dropColumn(['technical_notes', 'moscow', 'business_value']);
        });

        Schema::table('sprints', function (Blueprint $table) {
            $table->dropColumn(['capacity_hours', 'review_notes', 'retrospective_notes']);
        });

        Schema::table('squads', function (Blueprint $table) {
            $table->dropColumn(['definition_of_ready', 'definition_of_done', 'wip_limit_per_user']);
        });
    }
};
