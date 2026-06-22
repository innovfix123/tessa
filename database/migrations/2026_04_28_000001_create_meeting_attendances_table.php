<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meeting_attendances', function (Blueprint $table) {
            $table->id();
            $table->string('meeting_id', 50);
            $table->date('occurrence_date');
            $table->unsignedBigInteger('user_id');
            $table->enum('status', ['present', 'absent']);
            $table->string('source', 30)->default('slack_huddle_sync');
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->timestamps();

            $table->unique(['meeting_id', 'occurrence_date', 'user_id'], 'meeting_attendance_unique');
            $table->index(['user_id', 'occurrence_date']);
            $table->index(['meeting_id', 'occurrence_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_attendances');
    }
};
