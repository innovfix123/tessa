<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_ad_reports', function (Blueprint $table) {
            $table->id();
            $table->string('project', 50)->default('hima');
            $table->string('campaign_name', 500);
            $table->string('currency_code', 10)->default('INR');
            $table->decimal('cost', 12, 2)->default(0);
            $table->decimal('avg_cpc', 12, 2)->nullable();
            $table->decimal('ctr', 12, 8)->nullable();
            $table->decimal('cpi', 12, 2)->nullable();          // CPI (CE)
            $table->decimal('cpr', 12, 2)->nullable();           // Cost Per Registration
            $table->decimal('cpftd', 12, 2)->nullable();         // Cost Per First Time Deposit
            $table->decimal('cp_d1mp', 12, 2)->nullable();       // Cost Per Day-1 Monthly Purchase
            $table->integer('purchases')->default(0);
            $table->decimal('cpp', 12, 2)->nullable();           // Cost Per Purchase
            $table->decimal('purchase_value', 14, 2)->default(0);
            $table->date('reporting_date');
            $table->integer('uploaded_by');
            $table->string('source_file', 500)->nullable();
            $table->char('row_hash', 64);
            $table->timestamps();

            $table->unique('row_hash', 'google_ad_reports_unique_row');
            $table->index('reporting_date');
            $table->index('uploaded_by');
            $table->index('project');

            $table->foreign('uploaded_by')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_ad_reports');
    }
};
