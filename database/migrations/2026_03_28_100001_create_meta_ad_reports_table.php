<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_ad_reports', function (Blueprint $table) {
            $table->id();
            $table->string('campaign_name', 500);
            $table->string('ad_set_name', 500);
            $table->string('ad_name', 500);
            $table->bigInteger('reach')->default(0);
            $table->bigInteger('impressions')->default(0);
            $table->decimal('frequency', 12, 8)->default(0);
            $table->string('result_type', 100)->nullable();
            $table->integer('results')->default(0);
            $table->decimal('amount_spent', 12, 2)->default(0);
            $table->decimal('cost_per_result', 12, 8)->nullable();
            $table->decimal('cpc', 12, 8)->nullable();
            $table->decimal('cpm', 12, 8)->nullable();
            $table->decimal('ctr', 12, 8)->nullable();
            $table->integer('app_installs')->default(0);
            $table->decimal('cost_per_install', 12, 8)->nullable();
            $table->integer('new_user_first_purchase')->default(0);
            $table->decimal('cost_per_first_purchase', 12, 8)->nullable();
            $table->date('reporting_starts');
            $table->date('reporting_ends');
            $table->integer('uploaded_by');
            $table->string('source_file', 500)->nullable();
            // SHA-256 hash of campaign+adset+ad+dates for dedup
            $table->char('row_hash', 64);
            $table->timestamps();

            $table->unique('row_hash', 'meta_ad_reports_unique_row');
            $table->index('reporting_starts');
            $table->index('reporting_ends');
            $table->index('uploaded_by');

            $table->foreign('uploaded_by')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_ad_reports');
    }
};
