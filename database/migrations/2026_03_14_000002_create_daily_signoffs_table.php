<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_signoffs', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id');
            $table->date('signoff_date');
            $table->timestamp('signed_off_at');
            $table->json('pending_snapshot')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['user_id', 'signoff_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_signoffs');
    }
};
