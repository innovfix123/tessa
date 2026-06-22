<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('releases')) {
            return;
        }
        Schema::create('releases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('version', 50);
            $table->string('title');
            $table->text('description')->nullable();
            $table->enum('status', ['planned', 'in_progress', 'testing', 'released', 'delayed', 'cancelled'])->default('planned');
            $table->unsignedTinyInteger('progress')->default(0);
            $table->date('planned_date');
            $table->date('actual_date')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('releases');
    }
};
