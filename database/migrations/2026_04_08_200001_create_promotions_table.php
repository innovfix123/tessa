<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id');
            $table->date('effective_date');
            $table->string('old_designation', 150)->nullable();
            $table->string('new_designation', 150)->nullable();
            $table->unsignedBigInteger('old_role_id')->nullable();
            $table->unsignedBigInteger('new_role_id')->nullable();
            $table->unsignedBigInteger('old_department_id')->nullable();
            $table->unsignedBigInteger('new_department_id')->nullable();
            $table->unsignedBigInteger('salary_revision_id')->nullable();
            $table->enum('promotion_type', ['promotion', 'role_change', 'department_transfer', 'increment'])->default('promotion');
            $table->text('notes')->nullable();
            $table->integer('promoted_by')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('promoted_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('salary_revision_id')->references('id')->on('salary_revisions')->nullOnDelete();
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotions');
    }
};
