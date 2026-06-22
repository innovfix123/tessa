<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salary_revisions', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id');
            $table->date('effective_date');
            $table->decimal('previous_monthly_salary', 12, 2)->nullable();
            $table->decimal('new_monthly_salary', 12, 2)->nullable();
            $table->decimal('previous_annual_ctc', 14, 2)->nullable();
            $table->decimal('new_annual_ctc', 14, 2)->nullable();
            $table->string('revision_reason', 500)->nullable();
            $table->integer('revised_by')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('revised_by')->references('id')->on('users')->nullOnDelete();
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_revisions');
    }
};
