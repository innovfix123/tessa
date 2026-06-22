<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('time_slots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('timesheet_id');
            $table->time('start_time');
            $table->time('end_time');
            $table->decimal('duration_hours', 5, 2);
            $table->enum('type', ['regular', 'overtime'])->default('regular');
            $table->text('description');
            $table->timestamps();

            $table->index('timesheet_id');
            $table->foreign('timesheet_id')->references('id')->on('timesheets')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('time_slots');
    }
};
