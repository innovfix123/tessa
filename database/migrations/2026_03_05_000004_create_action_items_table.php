<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('action_items')) {
            return;
        }
        Schema::create('action_items', function (Blueprint $table) {
            $table->id();
            $table->string('meeting_id', 50);
            $table->date('week_key');
            $table->text('task');
            $table->string('owner', 100)->default('');
            $table->date('deadline')->nullable();
            $table->enum('status', ['pending', 'in_progress', 'done', 'blocked'])->default('pending');
            $table->enum('priority', ['high', 'medium', 'low'])->default('medium');
            $table->string('linked_kpi', 100)->nullable();
            $table->date('completed_at')->nullable();
            $table->text('comment')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
            $table->index(['meeting_id', 'week_key']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('action_items');
    }
};
