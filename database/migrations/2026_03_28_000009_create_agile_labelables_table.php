<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('agile_labelables')) {
            return;
        }
        Schema::create('agile_labelables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('label_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('labelable_id');
            $table->string('labelable_type', 50);
            $table->timestamps();

            $table->unique(['label_id', 'labelable_id', 'labelable_type'], 'label_labelable_unique');
            $table->index(['labelable_id', 'labelable_type'], 'labelable_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agile_labelables');
    }
};
