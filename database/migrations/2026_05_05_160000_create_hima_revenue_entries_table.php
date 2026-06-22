<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hima_revenue_entries', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->decimal('collection', 14, 2)->nullable();
            $table->decimal('zocket_meta_ads_without_gst', 14, 2)->nullable();
            $table->decimal('hima_creator', 14, 2)->nullable();
            $table->decimal('g_ads_1_without_gst', 14, 2)->nullable();
            $table->decimal('g_ads_2_without_gst', 14, 2)->nullable();
            $table->decimal('payout', 14, 2)->nullable();
            $table->decimal('day0_revenue', 14, 2)->nullable();
            $table->text('notes')->nullable();
            $table->integer('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hima_revenue_entries');
    }
};
