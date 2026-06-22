<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checklists', function (Blueprint $table) {
            $table->id();
            $table->integer('assigned_by');
            $table->integer('assigned_to');
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('assigned_by');
            $table->index('assigned_to');
            $table->foreign('assigned_by')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('assigned_to')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('checklist_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('checklist_id');
            $table->string('title', 300);
            $table->integer('position')->default(0);
            $table->timestamps();

            $table->foreign('checklist_id')->references('id')->on('checklists')->cascadeOnDelete();
            $table->index(['checklist_id', 'position']);
        });

        Schema::create('checklist_item_completions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('checklist_item_id');
            $table->integer('user_id');
            $table->date('check_date');
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();

            $table->foreign('checklist_item_id')->references('id')->on('checklist_items')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            // One row per item per assignee per day; flipping the box just
            // toggles checked_at on this row instead of inserting/deleting.
            $table->unique(['checklist_item_id', 'user_id', 'check_date'], 'checklist_completion_uniq');
            $table->index(['user_id', 'check_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checklist_item_completions');
        Schema::dropIfExists('checklist_items');
        Schema::dropIfExists('checklists');
    }
};
