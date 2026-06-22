<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table("bugs", function (Blueprint $table) {
            $table->tinyInteger("story_points")->unsigned()->nullable()->after("priority");
        });
    }

    public function down(): void
    {
        Schema::table("bugs", function (Blueprint $table) {
            $table->dropColumn("story_points");
        });
    }
};
