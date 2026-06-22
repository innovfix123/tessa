<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_first_participants', function (Blueprint $t) {
            $t->string('assigned_conductor', 100)->nullable()->after('exam_notes');
            $t->index('assigned_conductor');
        });
    }

    public function down(): void
    {
        Schema::table('ai_first_participants', function (Blueprint $t) {
            $t->dropIndex(['assigned_conductor']);
            $t->dropColumn('assigned_conductor');
        });
    }
};
