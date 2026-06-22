<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manager_work_reviews', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('manager_id');       // reviewer (user with subordinates)
            $table->unsignedBigInteger('subordinate_id');   // person being reviewed
            $table->date('week_key');                       // Friday of that week, IST
            $table->unsignedTinyInteger('rating');          // 1..5
            $table->text('feedback')->nullable();
            $table->timestamp('submitted_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique(['manager_id', 'subordinate_id', 'week_key'], 'mwr_unique');
            $table->index('week_key');
            $table->index(['subordinate_id', 'week_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manager_work_reviews');
    }
};
