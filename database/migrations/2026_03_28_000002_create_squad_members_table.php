<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('squad_members')) {
            return;
        }
        Schema::create('squad_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('squad_id')->constrained()->cascadeOnDelete();
            $table->integer('user_id');
            $table->string('role_in_squad', 32)->default('member');
            $table->timestamp('joined_at')->useCurrent();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['squad_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('squad_members');
    }
};
