<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weekly_timesheets', function (Blueprint $table) {
            $table->id();
            // users.id is a signed int — match it (never bigint/unsigned).
            $table->integer('user_id');
            $table->date('week_start');           // Monday of the week (the key)
            $table->date('week_end');             // Sunday (denormalized, for display)
            $table->decimal('regular_hours', 5, 2)->default(0);
            $table->text('regular_summary')->nullable();
            $table->decimal('overtime_hours', 5, 2)->default(0);
            $table->text('overtime_summary')->nullable();
            $table->decimal('total_hours', 6, 2)->default(0);
            $table->enum('status', ['draft', 'submitted'])->default('submitted');
            $table->timestamp('submitted_at')->nullable();
            $table->integer('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'week_start']);
            $table->index(['week_start']);
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weekly_timesheets');
    }
};
