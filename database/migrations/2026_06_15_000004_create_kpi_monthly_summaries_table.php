<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Month-end AI verdicts. For each subject + month, the AI reads that month's
 * weekly notes and produces a per-KPI summary (was the target met, and to what
 * %) plus one person-level overall narrative (kpi_item_id = NULL).
 *
 * Regenerated idempotently by kpi:generate-monthly-summaries (delete the
 * month's rows for the user, then reinsert).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kpi_monthly_summaries', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id');                           // subject
            $table->unsignedBigInteger('kpi_item_id')->nullable(); // NULL = person-level overall summary
            $table->string('month_key', 7);                       // 'YYYY-MM'
            $table->text('summary_text')->nullable();
            $table->smallInteger('percentage_met')->nullable();   // 0..100+ estimate, null if undeterminable
            $table->string('status', 20)->nullable();             // met | partial | missed | unknown
            $table->timestamp('generated_at')->nullable();
            $table->string('model')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'month_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpi_monthly_summaries');
    }
};
