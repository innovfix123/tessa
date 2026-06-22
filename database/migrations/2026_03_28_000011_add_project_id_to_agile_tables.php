<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add project_id to epics
        if (! Schema::hasColumn('epics', 'project_id')) {
            Schema::table('epics', function (Blueprint $table) {
                $table->foreignId('project_id')->nullable()->after('description')->constrained()->nullOnDelete();
                $table->index('project_id');
            });
        }

        // Add project_id to stories
        if (! Schema::hasColumn('stories', 'project_id')) {
            Schema::table('stories', function (Blueprint $table) {
                $table->foreignId('project_id')->nullable()->after('acceptance_criteria')->constrained()->nullOnDelete();
                $table->index('project_id');
            });
        }

        // Add project_id to bugs
        if (! Schema::hasColumn('bugs', 'project_id')) {
            Schema::table('bugs', function (Blueprint $table) {
                $table->foreignId('project_id')->nullable()->after('steps_to_reproduce')->constrained()->nullOnDelete();
                $table->index('project_id');
            });
        }

        // Add project_id to sprints
        if (! Schema::hasColumn('sprints', 'project_id')) {
            Schema::table('sprints', function (Blueprint $table) {
                $table->foreignId('project_id')->nullable()->after('goal')->constrained()->nullOnDelete();
                $table->index('project_id');
            });
        }
    }

    public function down(): void
    {
        Schema::table('epics', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
            $table->dropColumn('project_id');
        });
        Schema::table('stories', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
            $table->dropColumn('project_id');
        });
        Schema::table('bugs', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
            $table->dropColumn('project_id');
        });
        Schema::table('sprints', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
            $table->dropColumn('project_id');
        });
    }
};
