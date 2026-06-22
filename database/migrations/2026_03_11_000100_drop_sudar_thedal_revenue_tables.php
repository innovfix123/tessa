<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('thedal_sync_log');
        Schema::dropIfExists('thedal_meta_ad_weekly');
        Schema::dropIfExists('thedal_razorpay_payments');
        Schema::dropIfExists('sync_log');
        Schema::dropIfExists('meta_ad_weekly');
        Schema::dropIfExists('razorpay_payments');
    }

    public function down(): void
    {
        if (! Schema::hasTable('razorpay_payments')) {
            Schema::create('razorpay_payments', function (Blueprint $table) {
                $table->string('payment_id', 50)->primary();
                $table->integer('amount_paise');
                $table->string('status', 20);
                $table->string('contact', 100)->nullable();
                $table->string('email', 100)->nullable();
                $table->integer('created_at');
                $table->timestamp('synced_at')->useCurrent();
                $table->index('created_at');
                $table->index('status');
            });
        }

        if (! Schema::hasTable('meta_ad_weekly')) {
            Schema::create('meta_ad_weekly', function (Blueprint $table) {
                $table->date('week_start')->primary();
                $table->date('week_end');
                $table->integer('spend')->default(0);
                $table->integer('installs')->default(0);
                $table->timestamp('synced_at')->useCurrent()->useCurrentOnUpdate();
            });
        }

        if (! Schema::hasTable('sync_log')) {
            Schema::create('sync_log', function (Blueprint $table) {
                $table->string('source', 20)->primary();
                $table->integer('last_sync_ts')->default(0);
                $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            });
        }

        if (! Schema::hasTable('thedal_razorpay_payments')) {
            Schema::create('thedal_razorpay_payments', function (Blueprint $table) {
                $table->string('payment_id', 50)->primary();
                $table->integer('amount_paise');
                $table->string('status', 20);
                $table->string('contact', 100)->nullable();
                $table->string('email', 100)->nullable();
                $table->integer('created_at');
                $table->timestamp('synced_at')->useCurrent();
                $table->index('created_at');
                $table->index('status');
            });
        }

        if (! Schema::hasTable('thedal_meta_ad_weekly')) {
            Schema::create('thedal_meta_ad_weekly', function (Blueprint $table) {
                $table->date('week_start')->primary();
                $table->date('week_end');
                $table->integer('spend')->default(0);
                $table->integer('installs')->default(0);
                $table->timestamp('synced_at')->useCurrent()->useCurrentOnUpdate();
            });
        }

        if (! Schema::hasTable('thedal_sync_log')) {
            Schema::create('thedal_sync_log', function (Blueprint $table) {
                $table->string('source', 20)->primary();
                $table->integer('last_sync_ts')->default(0);
                $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            });
        }
    }
};
