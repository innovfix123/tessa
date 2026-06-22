<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('escalations')) {
            return;
        }
        Schema::create('escalations', function (Blueprint $table) {
            $table->id();
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->enum('severity', ['P0', 'P1', 'P2', 'P3'])->default('P2');
            $table->enum('category', ['app_crash', 'bug', 'payment', 'creator', 'user_complaint', 'other'])->default('other');
            $table->enum('status', ['open', 'in_progress', 'escalated', 'resolved', 'closed'])->default('open');
            $table->unsignedBigInteger('raised_by');
            $table->string('assigned_to_role', 20)->default('coo');
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->text('resolution_note')->nullable();
            $table->timestamps();
            $table->index(['assigned_to_role', 'status']);
            $table->index('raised_by');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('escalations');
    }
};
