<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KPI scorecard definitions — one row per KPI a person is measured on.
 * Seeded from public/kpis.html (the ~27 people listed there); JP/admin
 * manages the rest. The monthly target is FIXED here; managers only add
 * weekly tracking notes (kpi_weekly_reports) against these.
 *
 * `user_id` / `created_by` are `integer` (signed) to match users.id — NOT
 * bigInteger (project convention).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kpi_scorecard_items', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id');                       // the subject this KPI belongs to
            $table->string('name');                           // e.g. "Monthly Revenue Growth"
            $table->string('description', 500)->nullable();   // the sub-line from kpis.html
            $table->string('target')->nullable();             // mixed units: "₹7.5 Cr", "45%", "3x", "0", "—"
            $table->unsignedSmallInteger('weight')->nullable(); // scorecard weight (sums to 100 per person)
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->integer('created_by')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpi_scorecard_items');
    }
};
