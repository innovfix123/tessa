<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // users.id is INT in this codebase; drop partial tables from a failed run if any
        Schema::dropIfExists('script_library_items');
        Schema::dropIfExists('script_generations');

        Schema::create('script_generations', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->string('language', 32);
            $table->string('category', 64);
            $table->string('topic', 64);
            $table->text('creative_brief')->nullable();
            $table->unsignedTinyInteger('requested_count');
            $table->json('scripts');
            $table->timestamps();
        });

        Schema::create('script_library_items', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreignId('script_generation_id')->nullable()->constrained('script_generations')->nullOnDelete();
            $table->text('body');
            $table->string('language', 32);
            $table->string('category', 64);
            $table->string('topic', 64);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('script_library_items');
        Schema::dropIfExists('script_generations');
    }
};
