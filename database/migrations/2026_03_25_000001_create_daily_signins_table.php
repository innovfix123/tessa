<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_signins', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id');
            $table->date('signin_date');
            $table->timestamp('signed_in_at');
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['user_id', 'signin_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_signins');
    }
};
