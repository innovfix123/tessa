<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creative_uploads', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id');
            $table->string('field_key', 100);
            $table->date('report_date');
            $table->string('file_path', 500);
            $table->string('file_name', 255);
            $table->unsignedBigInteger('file_size');
            $table->string('file_type', 20);
            $table->integer('uploaded_by');
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('uploaded_by')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['user_id', 'field_key', 'report_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creative_uploads');
    }
};
