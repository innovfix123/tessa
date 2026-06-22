<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tessa_tasks', function (Blueprint $table) {
            $table->tinyInteger('progress')->unsigned()->default(0)->after('blocker_note');
        });

        DB::statement("ALTER TABLE tessa_tasks MODIFY blocker_status ENUM('on_track','blocked','no_update','at_risk') DEFAULT 'no_update'");
    }

    public function down(): void
    {
        DB::statement("UPDATE tessa_tasks SET blocker_status = 'no_update' WHERE blocker_status = 'at_risk'");
        DB::statement("ALTER TABLE tessa_tasks MODIFY blocker_status ENUM('on_track','blocked','no_update') DEFAULT 'no_update'");

        Schema::table('tessa_tasks', function (Blueprint $table) {
            $table->dropColumn('progress');
        });
    }
};
