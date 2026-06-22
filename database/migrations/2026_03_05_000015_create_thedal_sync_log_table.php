<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('thedal_sync_log')) {
            return;
        }
        Schema::create('thedal_sync_log', function (Blueprint $table) {
            $table->string('source', 20)->primary();
            $table->integer('last_sync_ts')->default(0);
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('thedal_sync_log');
    }
};
