<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('issued_letters', function (Blueprint $table) {
            $table->id();
            $table->string('letter_type', 16);          // 'offer' | 'appointment'
            $table->string('employee_category', 16);    // 'freelancer' | 'intern' | 'fulltime'
            $table->integer('recipient_user_id')->nullable();
            $table->string('recipient_name', 200);
            $table->string('recipient_email', 200);
            $table->string('recipient_phone', 32)->nullable();
            $table->string('role_title', 200);
            $table->string('department', 100)->nullable();
            $table->date('start_date')->nullable();
            $table->date('letter_date')->nullable();
            $table->json('payload');
            $table->longText('body_html')->nullable();
            $table->boolean('body_overridden')->default(false);
            $table->string('pdf_path', 500);
            $table->integer('issued_by_user_id');
            $table->timestamp('issued_at')->useCurrent();
            $table->string('share_token', 64)->unique();
            $table->timestamps();

            $table->index('letter_type');
            $table->index('employee_category');
            $table->index('recipient_user_id');
            $table->index('issued_by_user_id');
            $table->foreign('recipient_user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('issued_by_user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issued_letters');
    }
};
