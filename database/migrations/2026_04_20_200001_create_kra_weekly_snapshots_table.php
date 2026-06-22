<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kra_weekly_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->date('week_start');
            $table->date('week_end');
            $table->string('role', 60);
            $table->decimal('discipline', 3, 1)->nullable();
            $table->decimal('deliverables', 3, 1)->nullable();
            $table->decimal('quality', 3, 1)->nullable();
            $table->decimal('manager_review', 3, 1)->nullable();
            $table->decimal('composite', 3, 1)->nullable();
            $table->json('weights');
            $table->timestamp('calculated_at');
            $table->timestamps();

            $table->unique(['user_id', 'week_start']);
            $table->index('week_start');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kra_weekly_snapshots');
    }
};
