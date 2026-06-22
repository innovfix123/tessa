<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onlycare_revenue_snapshots', function (Blueprint $table) {
            $table->id();
            $table->date('snapshot_date')->unique();
            $table->bigInteger('total_revenue');
            $table->integer('transactions_count');
            $table->timestamp('last_transaction_at')->nullable();
            $table->timestamp('source_as_of')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onlycare_revenue_snapshots');
    }
};
