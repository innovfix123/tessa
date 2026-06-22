<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('story_dependencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('story_id')->constrained('stories')->cascadeOnDelete();
            $table->foreignId('depends_on_story_id')->constrained('stories')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['story_id', 'depends_on_story_id'], 'story_deps_pair_unique');
            $table->index('depends_on_story_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('story_dependencies');
    }
};
