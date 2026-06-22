<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('kpi_targets')) {
            return;
        }
        Schema::create('kpi_targets', function (Blueprint $table) {
            $table->id();
            $table->string('person_id', 50);
            $table->date('week_key');
            $table->string('field_key', 100);
            $table->text('value')->nullable();
            $table->unsignedBigInteger('updated_by');
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->unique(['person_id', 'week_key', 'field_key']);
            $table->index(['week_key', 'person_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpi_targets');
    }
};
