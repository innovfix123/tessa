<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('kpi_definitions')) {
            return;
        }
        Schema::create('kpi_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 20); // ops, team, mkpi
            $table->string('person_id', 50)->nullable();
            $table->string('person_name', 100)->nullable();
            $table->string('person_role', 100)->nullable();
            $table->string('group_name', 100);
            $table->string('field_key', 100);
            $table->string('field_label', 200);
            $table->string('aggregation', 20)->nullable(); // sum, avg, latest
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->index(['scope', 'person_id']);
            $table->index(['scope', 'group_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpi_definitions');
    }
};
