<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_attachments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('task_id');
            $table->unsignedBigInteger('message_id')->nullable();
            $table->integer('user_id');
            $table->string('file_name', 255);
            $table->string('file_path', 500);
            $table->unsignedInteger('file_size');
            $table->string('mime_type', 127);
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('task_id')->references('id')->on('tessa_tasks')->onDelete('cascade');
            $table->foreign('message_id')->references('id')->on('task_messages')->onDelete('set null');
            $table->index(['task_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_attachments');
    }
};
