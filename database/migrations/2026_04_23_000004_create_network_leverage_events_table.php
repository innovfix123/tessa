<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('network_leverage_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->string('week_key', 10);
            $table->date('event_date');
            $table->string('event_name');
            $table->string('co_attendees')->nullable();
            $table->unsignedInteger('attendee_count')->nullable();
            $table->text('contacts')->nullable();
            $table->text('linkedin_urls')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'week_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('network_leverage_events');
    }
};
