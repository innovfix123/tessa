<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('daily_reports')) {
            return;
        }
        Schema::create('daily_reports', function (Blueprint $table) {
            $table->id();
            $table->string('person_id', 50);
            $table->date('report_date');
            $table->string('field_key', 100);
            $table->text('value')->nullable();
            $table->unsignedBigInteger('updated_by');
            $table->timestamp('updated_at')->useCurrent();
            $table->unique(['person_id', 'report_date', 'field_key']);
            $table->index(['person_id', 'report_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_reports');
    }
};
