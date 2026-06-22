<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('meetings')) {
            return;
        }
        Schema::create('meetings', function (Blueprint $table) {
            $table->id();
            $table->string('meeting_key', 50)->unique();
            $table->string('title', 255);
            $table->string('owner', 100);
            $table->string('day_of_week', 10)->nullable();
            $table->string('time', 10);
            $table->enum('recurrence', ['daily_weekdays', 'weekly', 'none'])->default('none');
            $table->enum('portal', ['ops', 'ceo', 'coo', 'cmo', 'cfo']);
            $table->json('attendees')->nullable();
            $table->unsignedBigInteger('created_by')->default(0);
            $table->timestamps();
            $table->index('portal');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meetings');
    }
};
