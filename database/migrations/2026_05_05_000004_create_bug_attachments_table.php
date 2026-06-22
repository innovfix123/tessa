<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bug_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bug_id')->constrained('bugs')->cascadeOnDelete();
            $table->string('path');                    // disk path under storage/app/public/bug-attachments
            $table->string('original_name')->nullable();
            $table->string('mime', 128)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->unsignedInteger('uploaded_by')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index('bug_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bug_attachments');
    }
};
