<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Weekly KPI tracking notes — the editable text a manager fills each Friday
 * for each of a subject's KPI items. One row per (kpi_item, week_key).
 *
 * Never locked: a manager may revise on any later Friday (saving bumps
 * updated_at; submitted_at = first-filled, drives the Slack nudge). Mirrors
 * manager_work_reviews: no created_at column.
 *
 * `kpi_item_id` is unsignedBigInteger (matches kpi_scorecard_items.id);
 * `user_id` / `manager_id` are integer (signed) to match users.id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kpi_weekly_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('kpi_item_id');   // -> kpi_scorecard_items.id
            $table->integer('user_id');                  // subject (denormalised = item.user_id)
            $table->integer('manager_id');               // who filled it
            $table->date('week_key');                    // Friday of that week, IST
            $table->text('report_text')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique(['kpi_item_id', 'week_key'], 'kwr_item_week_unique');
            $table->index(['user_id', 'week_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpi_weekly_reports');
    }
};
