<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meeting_skips', function (Blueprint $table) {
            $table->id();
            $table->string('meeting_key', 50);
            $table->date('skip_date');
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->unique(['meeting_key', 'skip_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_skips');
    }
};
