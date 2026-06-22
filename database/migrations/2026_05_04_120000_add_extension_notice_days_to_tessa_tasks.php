<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tessa_tasks', function (Blueprint $table) {
            $table->tinyInteger('extension_notice_days')->unsigned()->nullable()->after('pending_extension_days');
        });
    }

    public function down(): void
    {
        Schema::table('tessa_tasks', function (Blueprint $table) {
            $table->dropColumn('extension_notice_days');
        });
    }
};
