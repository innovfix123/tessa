<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('freelance_hr_applicants')) {
            return;
        }
        Schema::create('freelance_hr_applicants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('resume_file');
            $table->enum('status', ['pending', 'selected', 'not_selected'])->default('pending');
            $table->string('charge')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique('resume_file');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('freelance_hr_applicants');
    }
};
