<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timesheets', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id');
            $table->date('work_date');
            $table->date('week_start');
            $table->decimal('total_hours', 5, 2)->default(0);
            $table->decimal('regular_hours', 5, 2)->default(0);
            $table->decimal('overtime_hours', 5, 2)->default(0);
            $table->decimal('amount', 10, 2)->default(0);
            $table->decimal('hourly_rate_snapshot', 10, 2)->default(0);
            $table->text('aggregate_description')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'work_date']);
            $table->index(['user_id', 'week_start']);
            $table->index(['week_start']);
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('timesheets');
    }
};
