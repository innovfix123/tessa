<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sprints')) {
            return;
        }
        Schema::create('sprints', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->text('goal')->nullable();
            $table->foreignId('squad_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16)->default('planning');
            $table->date('start_date');
            $table->date('end_date');
            $table->unsignedSmallInteger('velocity')->nullable();
            $table->integer('created_by');
            $table->integer('meeting_id')->nullable();
            $table->timestamps();

            $table->foreign('created_by')->references('id')->on('users');
            $table->foreign('meeting_id')->references('id')->on('meetings')->nullOnDelete();
            $table->index(['squad_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sprints');
    }
};
