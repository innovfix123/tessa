<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_participants', function (Blueprint $table) {
            $table->timestamp('last_read_at')->nullable()->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('task_participants', function (Blueprint $table) {
            $table->dropColumn('last_read_at');
        });
    }
};
