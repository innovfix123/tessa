<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('epics')) {
            return;
        }
        Schema::create('epics', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('squad_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 16)->default('open');
            $table->string('priority', 16)->default('medium');
            $table->integer('owner_id')->nullable();
            $table->date('target_date')->nullable();
            $table->integer('created_by');
            $table->timestamps();

            $table->foreign('owner_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users');
            $table->index('status');
            $table->index('squad_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('epics');
    }
};
