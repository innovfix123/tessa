<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('thedal_meta_ad_weekly')) {
            return;
        }
        Schema::create('thedal_meta_ad_weekly', function (Blueprint $table) {
            $table->date('week_start')->primary();
            $table->date('week_end');
            $table->integer('spend')->default(0);
            $table->integer('installs')->default(0);
            $table->timestamp('synced_at')->useCurrent()->useCurrentOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('thedal_meta_ad_weekly');
    }
};
