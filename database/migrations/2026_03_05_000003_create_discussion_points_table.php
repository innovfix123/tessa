<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('discussion_points')) {
            return;
        }
        Schema::create('discussion_points', function (Blueprint $table) {
            $table->id();
            $table->string('meeting_id', 50);
            $table->date('week_key');
            $table->text('question');
            $table->text('answer')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->index(['meeting_id', 'week_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discussion_points');
    }
};
